<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Message\Pressed;
use Elmphant\Tui\Rendering\Line;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Widget\Bar\FocusHints;
use Elmphant\Tui\Widget\Bar\Footer;
use Elmphant\Tui\Widget\Form\Field;
use Elmphant\Tui\Widget\Form\Form;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Widget;

final readonly class CreateAccount extends App
{
    /**
     * @param array<string, string|bool|list<string>|null>|null $sent
     */
    public function __construct(private Form $form, private ?array $sent = null)
    {
    }

    public static function new(): self
    {
        return new self(
            Form::new('Create an account')
                ->withField(Field::text('username', 'Username', 'The login name of the new user')->withRequired())
                ->withField(Field::secret('password', 'Password', 'Leave empty to be asked later'))
                ->withField(Field::text('email', 'Email'))
                ->withField(Field::choice('site', 'Site', [
                    'main' => 'Main website',
                    'intranet' => 'Intranet portal',
                    'shop' => 'Shop frontend',
                ], 'Where the user starts'))
                ->withField(Field::flag('admin', 'Admin', 'Create the user with admin rights'))
                ->withField(Field::flag('maintainer', 'Maintainer', 'Allow access to the maintenance tools'))
                ->withSubmit('Run')
                ->withFoot('❯ bin/console account:create'),
        );
    }

    public function view(): Widget
    {
        return Layout::column(
            $this->bind('form')->on(Pressed::class, static fn (self $app): self => $app->cloneWith(sent: array_diff_key($app->form->values(), ['password' => true]))),
            $this->sent === null
                ? Footer::new()->withLeft(FocusHints::new())
                : Paragraph::fromLines([new Line([Span::token('Sent: ' . json_encode($this->sent, JSON_UNESCAPED_SLASHES), StyleToken::Success)])]),
        );
    }
}

Program::run(CreateAccount::new());
