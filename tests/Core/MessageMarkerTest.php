<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Elmphant\Tui\Message\HttpResultMsg;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\LoadingMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Message\ProcessFinishedMsg;
use Elmphant\Tui\Message\ProcessOutputMsg;
use Elmphant\Tui\Message\ProcessResultMsg;
use Elmphant\Tui\Message\QuitMsg;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Message\TickMsg;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use TypeError;

final class MessageMarkerTest extends TestCase
{
    #[Test]
    public function everyBuiltinMessageImplementsTheMarker(): void
    {
        $messages = [
            new ErrorMsg(new RuntimeException(), ErrorSource::Update),
            new HttpResultMsg(200, ''),
            new KeyMsg('a'),
            LoadingMsg::start(),
            new MouseMsg(0, 0, MouseAction::Press, MouseButton::Left),
            new PasteMsg('text'),
            new ProcessFinishedMsg(0, true),
            new ProcessOutputMsg('output'),
            new ProcessResultMsg('', '', 0, true),
            QuitMsg::Quit,
            new ResizeMsg(80, 24),
            new TickMsg(1.0),
        ];

        foreach ($messages as $message) {
            self::assertInstanceOf(Message::class, $message);
        }
    }

    #[Test]
    public function cmdMessageRejectsValuesOutsideTheMessageContract(): void
    {
        $method = new ReflectionMethod(Cmd::class, 'message');

        $this->expectException(TypeError::class);
        $method->invoke(null, 'plain string');
    }
}
