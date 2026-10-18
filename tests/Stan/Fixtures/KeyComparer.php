<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan\Fixtures;

use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;

final class KeyComparer
{
    public function quits(KeyMsg $message): bool
    {
        return $message->key === 'esc';
    }

    public function stays(KeyMsg $message): bool
    {
        return $message->key !== 'q' && $message->key !== Key::Escape;
    }

    public function direction(KeyMsg $message): string
    {
        return match ($message->key) {
            'k', Key::Up => 'up',
            'Down' => 'down',
            default => 'none',
        };
    }
}
