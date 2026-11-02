<?php

declare(strict_types=1);

namespace Elmphant\Tui\Text;

/**
 * @internal
 */
enum AnsiTokenType
{
    case Text;
    case Csi;
    case Osc;
    case Dcs;
    case Apc;
    case Pm;
    case Sos;
    case Ss3;
    case Escape;
}
