<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Message\Pressed;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\Button;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class APressedButtonTest extends TestCase
{
    #[Test]
    public function enterOnAButtonReachesItsHandler(): void
    {
        $harness = ModelHarness::for(new SavingApp(Button::new('Save')), 20, 3)->init();

        $harness->press('enter', 'enter');

        $model = $harness->model();
        self::assertInstanceOf(SavingApp::class, $model);
        self::assertSame(2, $model->saved);
    }

    #[Test]
    public function aClickOnAButtonReachesItsHandler(): void
    {
        $harness = ModelHarness::for(new SavingApp(Button::new('Save')), 20, 3)->init();

        $harness->click('Save');

        $model = $harness->model();
        self::assertInstanceOf(SavingApp::class, $model);
        self::assertSame(1, $model->saved);
    }

    #[Test]
    public function aDisabledButtonPressesNothing(): void
    {
        $harness = ModelHarness::for(new SavingApp(Button::new('Save')->withDisabled()), 20, 3)->init();

        $harness->press('enter');

        $model = $harness->model();
        self::assertInstanceOf(SavingApp::class, $model);
        self::assertSame(0, $model->saved);
    }
}

final readonly class SavingApp extends App
{
    public function __construct(public Button $save, public int $saved = 0)
    {
    }

    public function view(): Widget
    {
        return $this->bind('save')->on(Pressed::class, static fn (self $app): self => new self($app->save, $app->saved + 1));
    }
}
