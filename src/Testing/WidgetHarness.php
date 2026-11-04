<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Message\ProcessStream;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Interactive;
use Throwable;

/**
 * Host one focused widget in an app so tests use normal key, pointer, popup and effect routing.
 *
 * @template TWidget of Interactive
 */
final class WidgetHarness
{
    /**
     * @param ModelHarness<WidgetHost<TWidget>> $harness
     */
    private function __construct(private readonly ModelHarness $harness)
    {
    }

    /**
     * @template T of Interactive
     *
     * @param T $widget
     *
     * @return self<T>
     */
    public static function for(Interactive $widget, int $width = 80, int $height = 24, ?Theme $theme = null): self
    {
        /** @var ModelHarness<WidgetHost<T>> $harness */
        $harness = ModelHarness::for(new WidgetHost($widget), $width, $height, $theme)->init();

        return new self($harness);
    }

    /**
     * @return TWidget
     */
    public function widget(): Interactive
    {
        return $this->harness->model()->widget;
    }

    public function screen(): ScreenBuffer
    {
        return $this->harness->renderScreen();
    }

    /**
     * @return array<string, string>
     */
    public function focusedHints(): array
    {
        return $this->harness->focusedHints();
    }

    /**
     * @return self<TWidget>
     */
    public function press(string ...$patterns): self
    {
        $this->harness->press(...$patterns);

        return $this;
    }

    /**
     * @return self<TWidget>
     */
    public function type(string $text): self
    {
        $this->harness->type($text);

        return $this;
    }

    /**
     * @return self<TWidget>
     */
    public function click(string $text, int $nth = 0, int $offset = 0): self
    {
        $this->harness->click($text, $nth, $offset);

        return $this;
    }

    /**
     * @return self<TWidget>
     */
    public function clickAt(int $column, int $row): self
    {
        $this->harness->clickAt($column, $row);

        return $this;
    }

    /**
     * @return self<TWidget>
     */
    public function drag(int $fromColumn, int $fromRow, int $toColumn, int $toRow): self
    {
        $this->harness->drag($fromColumn, $fromRow, $toColumn, $toRow);

        return $this;
    }

    /**
     * @return self<TWidget>
     */
    public function paste(string $text): self
    {
        $this->harness->paste($text);

        return $this;
    }

    /**
     * @return self<TWidget>
     */
    public function wheel(string $text, int $notches): self
    {
        $this->harness->wheel($text, $notches);

        return $this;
    }

    /**
     * @template T of Cmd
     *
     * @param class-string<T> $type
     *
     * @return list<T>
     */
    public function issued(string $type = Cmd::class): array
    {
        return $this->harness->issued($type);
    }

    /**
     * @return self<TWidget>
     */
    public function answer(EffectId|string $effect, string $stdout = '', int $exitCode = 0, string $stderr = ''): self
    {
        $this->harness->answer($effect, $stdout, $exitCode, $stderr);

        return $this;
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @return self<TWidget>
     */
    public function respond(EffectId|string $effect, int $status = 200, string $body = '', array $headers = []): self
    {
        $this->harness->respond($effect, $status, $body, $headers);

        return $this;
    }

    /**
     * @return self<TWidget>
     */
    public function stream(EffectId|string $effect, string $output, ProcessStream $stream = ProcessStream::Stdout): self
    {
        $this->harness->stream($effect, $output, $stream);

        return $this;
    }

    /**
     * @return self<TWidget>
     */
    public function finish(EffectId|string $effect, int $exitCode = 0): self
    {
        $this->harness->finish($effect, $exitCode);

        return $this;
    }

    /**
     * @return self<TWidget>
     */
    public function tick(EffectId|string $effect, float $at = 0.0): self
    {
        $this->harness->tick($effect, $at);

        return $this;
    }

    /**
     * @return self<TWidget>
     */
    public function fail(EffectId|string $effect, Throwable|string $error): self
    {
        $this->harness->fail($effect, $error);

        return $this;
    }
}
