<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Support\Cloneable;

final readonly class ToastCmd extends Cmd
{
    use Cloneable;

    /**
     * @internal build one with Cmd::toast()
     */
    public function __construct(
        public string $text,
        public string $title = '',
        public int $width = 40,
        public float $seconds = 6.0,
        public ToastPosition $position = ToastPosition::TopRight,
        public ToastSeverity $severity = ToastSeverity::Info,
        public ?string $key = null,
    ) {
    }

    public function withKey(string $key): self
    {
        return $this->cloneWith(key: $key);
    }

    public function withSeverity(ToastSeverity $severity): self
    {
        return $this->cloneWith(severity: $severity);
    }

    public function withPosition(ToastPosition $position): self
    {
        return $this->cloneWith(position: $position);
    }

    public function withSeconds(float $seconds): self
    {
        return $this->cloneWith(seconds: $seconds);
    }

    public function withTitle(string $title): self
    {
        return $this->cloneWith(title: $title);
    }

    public function withWidth(int $columns): self
    {
        return $this->cloneWith(width: max(5, $columns));
    }
}
