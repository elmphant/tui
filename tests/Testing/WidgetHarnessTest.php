<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\ExecCmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\ProcessResultMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Testing\WidgetHarness;
use Elmphant\Tui\Widget\Choice\Checkbox;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Structure\Panel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WidgetHarnessTest extends TestCase
{
    #[Test]
    public function aWidgetHostedAloneHoldsTheKeyboard(): void
    {
        $field = WidgetHarness::for(TextInput::new()->withPlaceholder('name'), 20, 1)->type('Ada');

        self::assertSame('Ada', $field->widget()->value());
        self::assertSame(0, $field->screen()->rowOf('Ada'));
    }

    #[Test]
    public function aClickReachesTheWidgetWhereItIsDrawn(): void
    {
        $box = WidgetHarness::for(Checkbox::new('Follow'), 20, 1)->click('Follow');

        self::assertTrue($box->widget()->isChecked());
    }

    #[Test]
    public function theFieldsOfAPaneAreStopsTheKeyboardWalks(): void
    {
        $form = WidgetHarness::for(Panel::new('Form')->withChildren([TextInput::new()->withPlaceholder('a'), TextInput::new()->withPlaceholder('b')]), 30, 8)
            ->press('tab')
            ->type('x');

        $fields = [...$form->widget()->children()];
        self::assertInstanceOf(TextInput::class, $fields[1]);
        self::assertSame('x', $fields[1]->value());
    }

    #[Test]
    public function theAnswerToAnEffectTheWidgetStartedReachesIt(): void
    {
        $loader = WidgetHarness::for(new Loader(), 20, 1)->press('enter');

        self::assertCount(1, $loader->issued(ExecCmd::class));
        self::assertSame('done', $loader->answer('load', stdout: 'done')->widget()->loaded);
        self::assertSame(0, $loader->screen()->rowOf('done'));
    }

    #[Test]
    public function theHintsAreThoseOfWhatHoldsTheKeyboard(): void
    {
        $hints = WidgetHarness::for(Checkbox::new('Follow'), 20, 1)->focusedHints();

        self::assertNotSame([], $hints);
    }
}

final readonly class Loader implements Focusable
{
    public function __construct(public string $loaded = '')
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return match (true) {
            $message instanceof KeyMsg && $message->key === Key::Enter => new UpdateResult($this, Cmd::exec(['load'], EffectId::named('load'))),
            $message instanceof ProcessResultMsg && $message->isFrom('load') => new UpdateResult(new self($message->stdout)),
            default => new UpdateResult($this),
        };
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $frame->putString($area->x, $area->y, $this->loaded);
    }
}
