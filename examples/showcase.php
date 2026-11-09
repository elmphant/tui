<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\AppActions;
use Elmphant\Tui\Application\InputContext;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\Presets\VimMode;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\ScreenMode;
use Elmphant\Tui\Widget\Alignment;
use Elmphant\Tui\Widget\Bar\FocusHints;
use Elmphant\Tui\Widget\Bar\Footer;
use Elmphant\Tui\Widget\Bar\Header;
use Elmphant\Tui\Widget\Choice\CheckboxGroup;
use Elmphant\Tui\Widget\Choice\Radio;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Feedback\ActivityBar;
use Elmphant\Tui\Widget\Feedback\ProgressBar;
use Elmphant\Tui\Widget\Field\EchoMode;
use Elmphant\Tui\Widget\Field\InputDecoration;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Flex;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Prose\Separator;
use Elmphant\Tui\Widget\Prose\Spacer;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Container;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Structure\TabBar;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use Elmphant\Tui\Widget\Table\Table;
use Elmphant\Tui\Widget\Widget;

final readonly class PackagesArrived implements Message
{
    /**
     * @param array<string, string> $packages
     */
    public function __construct(public array $packages)
    {
    }
}

final readonly class Showcase extends App
{
    public function __construct(
        public Panel $profile,
        public Panel $project,
        public TabBar $settings,
        public ?ListView $packages = null,
        public bool $vimMode = false,
    ) {
    }

    public static function new(): self
    {
        return new self(self::profilePanel(), self::projectPanel(), self::settingsTabs());
    }

    public function view(): Widget
    {
        $header = Header::new()
            ->withCenter(Text::line(
                Span::styled('elmphant/tui', new Style(foreground: Color::hex('B58900'), attributes: Attr::BOLD)),
                Span::styled(' █▓▒░', new Style(foreground: Color::hex('268BD2'))),
            ))
            ->withRight(Text::token('v0.1', StyleToken::Dim))
            ->withPadding(Spacing::horizontal(1))
            ->withRule();

        $panels = Flex::row()
            ->withWrap()
            ->withGap(1)
            ->add($this->bind('profile'), Constraint::min(26)->withFlex()->withBasis(34))
            ->add($this->bind('project'), Constraint::min(32)->withFlex(2)->withBasis(48))
            ->add($this->bind('settings'), Constraint::min(26)->withFlex()->withBasis(34));

        $packages = Panel::new('Packages')->add(
            $this->packages === null ? Text::token('Loading…', StyleToken::Dim) : $this->bind('packages'),
        );

        $footer = Footer::new()->withPadding(Spacing::horizontal(1))->withLeft(FocusHints::new());
        if ($this->packages === null) {
            $footer = $footer->withRight(ActivityBar::new('Fetching packages…'));
        }

        return Layout::column(
            $header,
            Container::around(Layout::column($panels, $packages))->withPadding(Spacing::horizontal(1)),
            $footer,
        );
    }

    public function theme(): Theme
    {
        return Theme::solarizedDark();
    }

    protected function setup(): ?Cmd
    {
        return Cmd::after(1.2, static fn (): Message => new PackagesArrived([
            'acme/rocket-sled' => 'Sleds that go uphill, too',
            'acme/anvil' => 'Drops heavy things on a schedule',
            'moonbase/oxygen' => 'Keeps the crew breathing, with retries',
            'moonbase/cheese-miner' => 'Digs for cheese in low gravity',
            'teapot/brew' => 'Answers 418 to every request',
            'teapot/kettle-queue' => 'Boils jobs in order',
            'nimbus/umbrella' => 'Caches the rain',
            'nimbus/forecast' => 'Guesses tomorrow, mostly right',
            'pixel-farm/sheep' => 'Counts sheep so you can sleep',
            'pixel-farm/scarecrow' => 'Keeps the crows out of your logs',
            'blue-whale/sonar' => 'Pings services in the deep',
            'blue-whale/krill' => 'Tiny workers, very many',
        ]));
    }

    protected function actions(): AppActions
    {
        return parent::actions()->with(
            'vim',
            ['ctrl+v'],
            static fn (self $app): self => $app->cloneWith(vimMode: !$app->vimMode),
            $this->vimMode ? 'vim off' : 'vim on',
        );
    }

    protected function globalKeybindings(): Keybindings
    {
        return $this->vimMode
            ? VimMode::keybindings()
            : new Keybindings([Actions::HOME => ['g g'], Actions::END => ['G']]);
    }

    protected function onMessage(Message $message, InputContext $input): self
    {
        if (!$message instanceof PackagesArrived) {
            return $this;
        }

        return $this->cloneWith(packages: ListView::new(array_map(
            static fn (string $name, string $description): SimpleListItem => new SimpleListItem($name, $description),
            array_keys($message->packages),
            $message->packages,
        )));
    }

    private static function profilePanel(): Panel
    {
        return Panel::new('User Profile')
            ->withPadding(Spacing::horizontal(1))
            ->add(Text::token('Account', StyleToken::Title))
            ->add(Spacer::new())
            ->add(Text::new('Name'))
            ->add(TextInput::new()->withPlaceholder('Your name')->withDecoration(InputDecoration::Filled))
            ->add(Spacer::new())
            ->add(Text::new('Email'))
            ->add(TextInput::new()->withPlaceholder('you@example.com')->withDecoration(InputDecoration::Filled))
            ->add(Spacer::new())
            ->add(Text::new('Password'))
            ->add(TextInput::new()->withPlaceholder('Password')->withEchoMode(EchoMode::Password)->withDecoration(InputDecoration::Filled))
            ->add(Separator::new())
            ->add(CheckboxGroup::new(['I accept the terms and conditions', 'Subscribe to newsletter', 'Enable beta features'], 'Agreements'))
            ->add(Separator::new())
            ->add(Text::token('Role', StyleToken::Title))
            ->add(Spacer::new())
            ->add(Select::new(['Admin', 'Editor', 'Viewer', 'Guest']));
    }

    private static function projectPanel(): Panel
    {
        $done = static fn (string $task): Span => Span::token('✓ ' . $task, StyleToken::Success);
        $active = static fn (string $task): Span => Span::token('▸ ' . $task, StyleToken::Warning);
        $pending = static fn (string $task): string => '  ' . $task;

        $tasks = Table::new(
            [
                Column::new('Task', Constraint::flex(2)),
                Column::new('Status', Constraint::flex(1)),
                Column::new('Priority', Constraint::exact(8)),
            ],
            [
                Row::header('Sprint 1 — Infrastructure'),
                Row::new($done('Set up CI/CD pipeline'), 'Done', 'High'),
                Row::new($done('Fix login bug'), 'Done', Span::token('Critical', StyleToken::Error)),
                Row::new($active('Write unit tests'), 'Active', 'High'),
                Row::new($active('Code review'), 'Active', 'High'),
                Row::new($pending('Deploy to staging'), 'Pending', 'Medium'),
                Row::header('Sprint 2 — Features'),
                Row::new($active('Add dark mode'), 'Active', 'Medium'),
                Row::new($active('Refactor auth'), 'Active', 'Medium'),
                Row::new($pending('Update dependencies'), 'Pending', 'Low'),
                Row::new($pending('Add 2FA support'), 'Pending', 'High'),
                Row::new($pending('Mobile responsive'), 'Pending', 'Medium'),
                Row::header('Backlog'),
                Row::new($pending('Optimize queries'), 'Pending', 'High'),
                Row::new($pending('Write docs'), 'Pending', 'Low'),
                Row::new($pending('API rate limiting'), 'Pending', 'Medium'),
                Row::new($pending('Error monitoring'), 'Pending', 'High'),
            ],
        );

        $files = ListView::new([
            new SimpleListItem('README.md', 'Project documentation'),
            new SimpleListItem('CHANGELOG.md', 'Version history'),
            new SimpleListItem('composer.json', 'PHP dependencies'),
            new SimpleListItem('src/App.php', 'Main application entry'),
            new SimpleListItem('src/Router.php', 'HTTP routing'),
            new SimpleListItem('src/Auth.php', 'Authentication'),
            new SimpleListItem('tests/AppTest.php', 'Application tests'),
            new SimpleListItem('tests/RouterTest.php', 'Router tests'),
            new SimpleListItem('.env.example', 'Environment template'),
            new SimpleListItem('docker-compose.yml', 'Container config'),
        ])->withKeybindings(new Keybindings([Actions::SELECT => ['enter', 'space', 'ctrl+o']]));

        return Panel::new('Project')
            ->withPadding(Spacing::horizontal(1))
            ->add(Text::token('Task Board', StyleToken::Title))
            ->add(Spacer::new())
            ->add($tasks)
            ->add(Separator::new())
            ->add(Text::token('Recent Files', StyleToken::Title))
            ->add(Spacer::new())
            ->add($files);
    }

    private static function settingsTabs(): TabBar
    {
        $about = Paragraph::fromMarkdown(
            '**Elmphant TUI** is a terminal UI framework for *PHP 8.2+* '
            . "implementing **The Elm Architecture**.\n\n"
            . "Inspired by Go's `Bubbletea` and Rust's `Ratatui`, "
            . "it brings ~~complexity~~ *simplicity* to terminal interfaces.\n\n"
            . 'Features include **word wrapping**, ~~manual line breaks~~ '
            . '*automatic layout*, and `inline code` styling.'
        );

        return TabBar::new()
            ->withPadding(Spacing::horizontal(1))
            ->addTab('About', $about, Spacer::new(), Paragraph::fromText('— Built with love in PHP —')->withAlignment(Alignment::Center))
            ->addTab('Theme', Radio::new(['Dark', 'Light', 'Dracula', 'Solarized', 'Rosé Pine'], 'Theme'))
            ->addTab('Alerts', Text::token('Channel', StyleToken::Title), Spacer::new(), Select::new(['Email', 'Chat', 'SMS', 'None']))
            ->addTab(
                'Resources',
                ProgressBar::new(0.82)->withLabel('Score'),
                Spacer::new(),
                ProgressBar::new(0.67)->withLabel('Disk'),
                Spacer::new(),
                ProgressBar::new(0.34)->withLabel('RAM'),
            );
    }
}

Program::new(Showcase::new())->withScreenMode(ScreenMode::alternate(mouse: true))->start();
