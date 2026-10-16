<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Dispatch;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Widget\Interactive;
use LogicException;

/**
 * @internal reducer routing implementation shared by composite widgets
 */
final readonly class ChildDispatcher
{
    public function __construct(
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    /**
     * @template TChild of Interactive
     *
     * @param TChild $child
     *
     * @return DispatchResult<TChild>
     */
    public function dispatchOne(Interactive $child, Message $message, Rect $rect): DispatchResult
    {
        $wrapped = $child instanceof KeybindingsAware && $this->globalKeybindings !== null
            ? $child->withGlobalKeybindings($this->globalKeybindings)
            : $child;

        $result = $wrapped->update($message, $rect);
        /** @var TChild $updated */
        $updated = self::requireInteractive($result->model);

        return new DispatchResult(
            child: $updated === $wrapped ? $child : $updated,
            command: $result->command,
            changed: $updated !== $wrapped,
        );
    }

    /**
     * @template TChild of Interactive
     *
     * @param array<string, TChild> $children
     * @param array<string, Rect>   $rects
     *
     * @return BatchDispatchResult<TChild>
     */
    public function dispatchMap(array $children, array $rects, Message $message): BatchDispatchResult
    {
        $overrides = [];
        $commands = [];

        foreach ($children as $key => $child) {
            $rect = $rects[$key] ?? throw new LogicException("Missing rect for child key '$key'");
            $result = $this->dispatchOne($child, $message, $rect);

            if ($result->changed) {
                $overrides[$key] = $result->child;
            }
            if ($result->command !== null) {
                $commands[] = $result->command;
            }
        }

        return new BatchDispatchResult($overrides, Cmd::combine($commands));
    }

    /**
     * @template TChild of Interactive
     *
     * @param array<int, TChild> $children
     * @param array<int, Rect>   $rects
     *
     * @return IndexedBatchDispatchResult<TChild>
     */
    public function dispatchList(array $children, array $rects, Message $message): IndexedBatchDispatchResult
    {
        $overrides = [];
        $commands = [];

        foreach ($children as $index => $child) {
            $rect = $rects[$index] ?? throw new LogicException("Missing rect for child index $index");
            $result = $this->dispatchOne($child, $message, $rect);

            if ($result->changed) {
                $overrides[$index] = $result->child;
            }
            if ($result->command !== null) {
                $commands[] = $result->command;
            }
        }

        return new IndexedBatchDispatchResult($overrides, Cmd::combine($commands));
    }

    private static function requireInteractive(Reducer $model): Interactive
    {
        if (!$model instanceof Interactive) {
            throw new LogicException(sprintf('Interactive child update must return an Interactive model, got %s', get_debug_type($model)));
        }

        return $model;
    }
}
