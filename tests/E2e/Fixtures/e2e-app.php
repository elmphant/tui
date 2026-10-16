<?php

declare(strict_types=1);

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\Trace\JsonlTracer;
use Elmphant\Tui\Widget\Bar\Footer;
use Elmphant\Tui\Widget\Bar\Header;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Popup\ModalDismissed;
use Elmphant\Tui\Widget\Popup\Overlay;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Structure\TabBar;
use Elmphant\Tui\Widget\Widget;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

final readonly class E2eApp extends App
{
    public function __construct(
        public TabBar $tabs,
        public string $processMarker,
        public ?Modal $modal = null,
        public bool $emoji = false,
    ) {
    }

    public static function create(string $processMarker): self
    {
        $items = [];
        for ($index = 0; $index < 200; ++$index) {
            $items[] = new SimpleListItem(sprintf('item %03d', $index));
        }

        $form = Panel::new('Form')
            ->add(TextInput::new('type here'))
            ->add(Dropdown::new(Select::new(['alpha', 'beta', '漢字 gamma', 'delta 😀', 'epsilon'])))
            ->add(Text::new('Under the dropdown 漢字漢字'))
            ->add(Text::new('Under the dropdown 😀😀😀'))
            ->add(Text::new('Under the dropdown ascii'));

        return new self(
            TabBar::new()->addTab('Form')->add($form)->addTab('List')->add(ListView::new($items)),
            $processMarker,
        );
    }

    public function view(): Widget
    {
        $main = Layout::vertical(
            Header::new('E2E app'),
            Component::bind('tabs', $this->tabs, static fn (self $app, TabBar $tabs): self => $app->cloneWith(tabs: $tabs)),
            Text::new($this->emoji ? str_repeat('❤️', 20) : 'x' . str_repeat('漢', 20)),
            Footer::forHints(['F2' => 'modal', 'F5' => 'swap', 'F6' => 'process']),
        );

        return $this->modal === null
            ? $main
            : Overlay::new($main, Component::bind('modal', $this->modal, static fn (self $app, Modal $modal): self => $app->cloneWith(modal: $modal)));
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return match ($message->key) {
            Key::F2 => new UpdateResult($this->cloneWith(modal: Modal::new('Modal 漢字', Text::new('Modal body 😀 漢字'))->withWidth(50)->withHeight(40))),
            Key::F5 => new UpdateResult($this->cloneWith(emoji: !$this->emoji)),
            Key::F6 => new UpdateResult($this, Cmd::exec(['sleep', $this->processMarker])),
            default => UpdateResult::ignored($this),
        };
    }

    protected function onMessage(Message $message, Rect $screen): UpdateResult
    {
        return $message instanceof ModalDismissed
            ? new UpdateResult($this->cloneWith(modal: null))
            : UpdateResult::ignored($this);
    }
}

$program = Program::new(E2eApp::create($argv[1] ?? '600'))->withAltScreen();
$trace = getenv('E2E_TRACE');
($trace === false ? $program : $program->withTracer(JsonlTracer::toFile($trace)))->start();
