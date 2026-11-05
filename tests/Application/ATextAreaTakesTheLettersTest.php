<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\AppActions;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextArea;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ATextAreaTakesTheLettersTest extends TestCase
{
    #[Test]
    public function lettersTheAppBindsAreTypedIntoTheBody(): void
    {
        $harness = ModelHarness::for(new BodyEditor(TextArea::new()), width: 30, height: 5)->init();

        $harness->type('q?')->press('enter')->type('x');

        self::assertSame("q?\nx", $harness->model()->body->value());
        self::assertFalse($harness->model()->cleared);
    }
}

final readonly class BodyEditor extends App
{
    public function __construct(public TextArea $body, public bool $cleared = false)
    {
    }

    public function view(): Widget
    {
        return $this->bind('body');
    }

    protected function actions(): AppActions
    {
        return parent::actions()->with('clear', ['q'], static fn (self $app): self => $app->cloneWith(cleared: true), 'clear');
    }
}
