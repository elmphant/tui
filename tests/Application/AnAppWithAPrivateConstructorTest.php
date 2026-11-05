<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Widget;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnAppWithAPrivateConstructorTest extends TestCase
{
    #[Test]
    public function storingAWidgetSaysTheConstructorMustBePublic(): void
    {
        $harness = ModelHarness::for(PrivatelyBuiltApp::start(), 30, 3)->init();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PrivatelyBuiltApp::class . ' needs a public constructor');

        $harness->type('a');
    }
}

final readonly class PrivatelyBuiltApp extends App
{
    private function __construct(public TextInput $field)
    {
    }

    public static function start(): self
    {
        return new self(TextInput::new());
    }

    public function view(): Widget
    {
        return $this->bind('field');
    }
}
