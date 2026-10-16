<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\Popup\DropdownPopup;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The same C1 damage one layer up: an App broadcasting a click would hand it
 * to the sibling drawn beneath the popup.
 */
final class DropdownAppAcceptanceTest extends TestCase
{
    #[Test]
    public function clickingAnOptionDrawnOverTheSiblingPicksItAndKeepsTheKeyboardAlive(): void
    {
        $harness = ModelHarness::for(TwoDropdownApp::new(), 40, 18);
        $harness->dispatch(new KeyMsg(' '));
        $harness->renderFrame();

        $placements = $harness->floatPlacements();
        self::assertCount(1, $placements, 'the popup must be on the screen before the click can land on it');

        $popup = $placements[0]->float;
        self::assertInstanceOf(DropdownPopup::class, $popup);
        $optionRow = $popup->contentArea($placements[0]->placement)->y;
        $harness->dispatch(new MouseMsg(2, $optionRow, MouseAction::Press, MouseButton::Left));

        $app = self::appOf($harness);
        self::assertSame('json', $app->first->content()->selectedValue(), 'the drawn popup owns that cell');
        self::assertNull($app->second->content()->selectedValue(), 'the sibling under the popup saw nothing');
        self::assertSame('first', $harness->focused()?->value, 'the picker keeps focus');

        $harness->renderFrame();
        $harness->dispatch(new KeyMsg(' '));

        self::assertTrue(self::appOf($harness)->first->isOpen(), 'the keyboard still drives the same dropdown');
    }

    #[Test]
    public function aClickOpensAClosedDropdownAndLeavesItFocused(): void
    {
        $harness = ModelHarness::for(TwoDropdownApp::new(), 40, 18);
        $harness->renderFrame();

        $harness->dispatch(new MouseMsg(2, 0, MouseAction::Press, MouseButton::Left));

        $app = self::appOf($harness);
        self::assertTrue($app->first->isOpen(), 'one click opens it');
        self::assertSame('first', $harness->focused()?->value, 'and it ends up focused');
    }

    #[Test]
    public function aClickOpensTheDropdownThatDidNotHaveFocusYet(): void
    {
        $harness = ModelHarness::for(TwoDropdownApp::new(), 40, 18);
        $harness->renderFrame();

        self::assertSame('first', $harness->focused()?->value, 'the keyboard starts on the first row');

        $harness->dispatch(new MouseMsg(2, 1, MouseAction::Press, MouseButton::Left));

        $app = self::appOf($harness);
        self::assertTrue($app->second->isOpen(), 'App applies click-focus AFTER dispatch, so the click must open it unfocused');
        self::assertFalse($app->first->isOpen(), 'the row that had focus is left alone');

        $harness->dispatch(new KeyMsg(Key::Escape));

        self::assertFalse(
            self::appOf($harness)->second->isOpen(),
            'the keyboard arrived on it in the same click, so the next key is its to answer',
        );
    }

    /**
     * @param ModelHarness<TwoDropdownApp> $harness
     */
    private static function appOf(ModelHarness $harness): TwoDropdownApp
    {
        $model = $harness->model();

        if (!$model instanceof TwoDropdownApp) {
            throw new RuntimeException('the harness lost the app');
        }

        return $model;
    }
}

final readonly class TwoDropdownApp extends App
{
    use Cloneable;

    public function __construct(
        public Dropdown $first,
        public Dropdown $second,
    ) {
    }

    public static function new(): self
    {
        return new self(
            Dropdown::new(Select::new(['json', 'yaml'])),
            Dropdown::new(Select::new(['toml', 'ini'])),
        );
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind(
                ComponentId::named('first'),
                $this->first,
                static fn (self $app, Dropdown $dropdown): self => $app->cloneWith(first: $dropdown),
            ),
            Component::bind(
                ComponentId::named('second'),
                $this->second,
                static fn (self $app, Dropdown $dropdown): self => $app->cloneWith(second: $dropdown),
            ),
        );
    }
}
