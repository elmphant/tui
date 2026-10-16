<?php

declare(strict_types=1);

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Runtime\Model;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Program;
use Revolt\EventLoop;

$scenario = $argv[1] ?? 'normal';
$readyFile = getenv('ELMPHANT_PTY_READY');
$resultFile = getenv('ELMPHANT_PTY_RESULT');

if ($scenario === 'blocking') {
    if (!stream_set_blocking(STDIN, false)) {
        throw new RuntimeException('Unable to make inherited PTY input non-blocking');
    }
}

$model = new class($scenario, $readyFile, $resultFile) implements Model {
    public function __construct(
        private readonly string $scenario,
        private readonly string|false $readyFile,
        private readonly string|false $resultFile,
        private readonly int $resizeCount = 0,
    ) {
    }

    public function init(): ?Cmd
    {
        if ($this->readyFile !== false) {
            $readyFile = $this->readyFile;
            EventLoop::defer(static function () use ($readyFile): void {
                touch($readyFile);
            });
        }

        if ($this->scenario === 'exception') {
            throw new RuntimeException('intentional PTY scenario failure');
        }

        return in_array($this->scenario, ['normal', 'blocking'], true)
            ? Cmd::quit()
            : null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof ResizeMsg) {
            $next = new self(
                $this->scenario,
                $this->readyFile,
                $this->resultFile,
                $this->resizeCount + 1,
            );

            if ($this->scenario === 'resize' && $next->resizeCount >= 2) {
                if ($this->resultFile !== false) {
                    file_put_contents($this->resultFile, 'same-size-resize-rendered');
                }

                return UpdateResult::changed($next, Cmd::quit());
            }

            return UpdateResult::changed($next);
        }

        return UpdateResult::keep($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
        $frame->putString(0, 0, 'PTY hardening scenario');
    }
};

try {
    Program::new($model)->withAltScreen()->withMouseTracking()->start();
} finally {
    if ($scenario === 'blocking') {
        $metadata = stream_get_meta_data(STDIN);
        if (($metadata['blocked'] ?? null) !== false) {
            throw new RuntimeException('Program did not restore inherited non-blocking input');
        }

        // Do not leave the parent PTY's shared open-file description non-blocking.
        stream_set_blocking(STDIN, true);
    }
}
