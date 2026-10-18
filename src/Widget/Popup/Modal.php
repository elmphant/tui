<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Popup;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Block;
use Elmphant\Tui\Rendering\BorderStyle;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Widget\Committing;
use Elmphant\Tui\Widget\Dispatch\ChildDispatcher;
use Elmphant\Tui\Widget\Focus\CapturesKeys;
use Elmphant\Tui\Widget\Focus\InputLayer;
use Elmphant\Tui\Widget\Focus\Navigates;
use Elmphant\Tui\Widget\Focus\Navigation;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\PlacedChildren;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\SingleMount;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

/**
 * @template-covariant TContent of Widget
 */
final readonly class Modal implements Interactive, WidgetContainer, Navigates, InputLayer, KeybindingsAware, PublishesHints
{
    use Cloneable;
    use KeybindingsAwareBehavior;
    private const BORDERS = 2;

    /**
     * @param TContent $content
     */
    private function __construct(
        private string $title,
        private Widget $content,
        private bool $sizedToContent,
        private int $widthPercent,
        private int $heightPercent,
        private ?Style $borderStyleOverride = null,
        private ?Style $titleStyleOverride = null,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    /**
     * @template T of Widget
     *
     * @param T $content
     *
     * @return self<T>
     */
    public static function new(string $title, Widget $content): self
    {
        return new self(
            title: $title,
            content: $content,
            sizedToContent: false,
            widthPercent: 60,
            heightPercent: 60,
        );
    }

    /**
     * @return TContent
     */
    public function content(): Widget
    {
        return $this->content;
    }

    public function withTitle(string $title): static
    {
        return $this->cloneWith(title: $title);
    }

    /**
     * @return self<Widget>
     */
    public function withContent(Widget $content): self
    {
        return $this->cloneWith(content: $content);
    }

    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::CANCEL => ['esc'],
        ]);
    }

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints(['dismiss' => Actions::CANCEL]);
    }

    public function withGlobalKeybindings(?Keybindings $keybindings): static
    {
        return $this->cloneWith(
            globalKeybindings: $keybindings,
            content: $this->content instanceof KeybindingsAware
                ? $this->content->withGlobalKeybindings($keybindings)
                : $this->content,
        );
    }

    /**
     * Size the modal to its content instead of to a share of the screen.
     *
     * The width comes from the content's own column constraint, the height from
     * MeasureHeight; content that answers neither keeps the percentage sizing for
     * the dimension it cannot speak for.
     */
    public function withSizedToContent(bool $sizedToContent = true): static
    {
        return $this->cloneWith(sizedToContent: $sizedToContent);
    }

    public function withWidth(int $percent): static
    {
        return $this->cloneWith(widthPercent: max(10, min(100, $percent)));
    }

    public function withHeight(int $percent): static
    {
        return $this->cloneWith(heightPercent: max(10, min(100, $percent)));
    }

    public function withBorderStyle(Style $style): static
    {
        return $this->cloneWith(borderStyleOverride: $style);
    }

    public function withTitleStyle(Style $style): static
    {
        return $this->cloneWith(titleStyleOverride: $style);
    }

    /**
     * @return list<Widget>
     */
    public function children(): array
    {
        return [$this->content];
    }

    /**
     * @return list<array{Widget, Rect}>
     */
    public function placedChildren(Rect $area): array
    {
        return [[$this->content, $this->computeContentRect($area)]];
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    /**
     * Where a bound component inside holds the keyboard's stops, those are the
     * stops and the frame around them is not one.
     */
    public function navigation(): Navigation
    {
        return self::mountsAFocusable([$this->content], false) ? Navigation::Never : Navigation::Regular;
    }

    /**
     * Asked, never stored, for the reasons {@see Committing} gives.
     */
    public function dismisses(Message $message, Rect $area): bool
    {
        if ($message instanceof KeyMsg) {
            return $this->effectiveKeybindings()->matches($message, Actions::CANCEL)
                && !($this->content instanceof CapturesKeys && $this->content->capturesKeys());
        }

        return $message instanceof MouseMsg
            && $message->isClick()
            && !$message->inRect($this->computeModalRect($area));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return match (true) {
            $this->dismisses($message, $area) => new UpdateResult(
                $this,
                Cmd::message(new ModalDismissed()),
            ),

            $this->content instanceof Interactive => $this->updateContent($message, $area),

            default => new UpdateResult($this),
        };
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $modalRect = $this->computeModalRect($area);
        if ($modalRect->width < 3 || $modalRect->height < 3) {
            return;
        }

        $this->dimBackground($frame, $area);

        $baseBackground = $context->theme->base->backgroundValue();
        $this->clearModalArea($frame, $modalRect, $baseBackground);

        $theme = $context->theme;
        $block = Block::new()
            ->withBorder($theme->border)
            ->withBorderStyle($this->borderStyleOverride ?? $theme->focusedBorderStyle)
            ->withTitleStyle($this->titleStyleOverride ?? $theme->titleStyle)
            ->withTitle($this->title);
        $block->render($frame, $modalRect);
        $contentRect = $block->inner($modalRect);

        if ($contentRect->width > 0 && $contentRect->height > 0) {
            $frame->pushClip($contentRect);

            try {
                foreach (PlacedChildren::of($this, $area, $context) as [$child, $childArea]) {
                    $child->render($frame, $childArea, $context);
                }
            } finally {
                $frame->popClip();
            }
        }
    }

    /**
     * @return UpdateResult<static>
     */
    private function updateContent(Message $message, Rect $area): UpdateResult
    {
        assert($this->content instanceof Interactive);

        $result = (new ChildDispatcher())->dispatchOne($this->content, $message, $this->computeContentRect($area));

        return new UpdateResult(
            $result->changed ? $this->cloneWith(content: $result->child) : $this,
            $result->command,
        );
    }

    /**
     * @param iterable<Widget> $widgets
     */
    private static function mountsAFocusable(iterable $widgets, bool $mounted): bool
    {
        foreach ($widgets as $widget) {
            if ($mounted && $widget instanceof Focusable && (!$widget instanceof Navigates || $widget->navigation() !== Navigation::Never)) {
                return true;
            }

            if ($widget instanceof WidgetContainer && self::mountsAFocusable($widget->children(), $mounted || $widget instanceof SingleMount)) {
                return true;
            }
        }

        return false;
    }

    private function computeModalRect(Rect $area): Rect
    {
        $modalWidth = $this->fittedWidth($area) ?? max(3, (int)($area->width * $this->widthPercent / 100));
        $modalHeight = $this->fittedHeight($modalWidth - self::BORDERS)
            ?? max(3, (int)($area->height * $this->heightPercent / 100));

        $modalWidth = min($modalWidth, $area->width);
        $modalHeight = min($modalHeight, $area->height);
        $modalX = $area->x + (int)(($area->width - $modalWidth) / 2);
        $modalY = $area->y + (int)(($area->height - $modalHeight) / 2);

        return new Rect($modalX, $modalY, $modalWidth, $modalHeight);
    }

    private function fittedWidth(Rect $area): ?int
    {
        $contentWidth = $this->content->sizing()->columns->intrinsic();

        if (!$this->sizedToContent || $contentWidth === 0) {
            return null;
        }

        $title = CharWidth::stringWidth($this->title) + self::BORDERS;

        return min($area->width, max(3, $contentWidth + self::BORDERS, $title));
    }

    private function fittedHeight(int $contentWidth): ?int
    {
        if (!$this->sizedToContent || !$this->content instanceof MeasureHeight) {
            return null;
        }

        return max(3, $this->content->measureHeight(max(1, $contentWidth)) + self::BORDERS);
    }

    private function computeContentRect(Rect $area): Rect
    {
        $modalRect = $this->computeModalRect($area);
        $block = Block::new()->withBorder(BorderStyle::Rounded);

        return $block->inner($modalRect);
    }

    private function dimBackground(Frame $frame, Rect $area): void
    {
        for ($row = $area->y; $row < $area->bottom(); ++$row) {
            for ($column = $area->x; $column < $area->right(); ++$column) {
                $index = $row * $frame->width + $column;
                if ($index >= 0 && $index < $frame->width * $frame->height) {
                    $frame->putChar(
                        $column,
                        $row,
                        $frame->characters[$index],
                        $frame->foregrounds[$index],
                        $frame->backgrounds[$index],
                        $frame->attributes[$index] | Attr::FAINT,
                    );
                }
            }
        }
    }

    private function clearModalArea(Frame $frame, Rect $modalRect, int $background): void
    {
        $frame->fillRect($modalRect->x, $modalRect->y, $modalRect->width, $modalRect->height, 0, $background);
    }
}
