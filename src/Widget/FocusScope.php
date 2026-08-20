<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

/**
 * A widget whose components are a ring of their own once the keyboard is in one
 * of them: tab walks its controls and wraps, rather than carrying on into what
 * surrounds it. Getting in and out is an explicit focus command, so the reader
 * chooses to step into the chrome instead of finding it strung between the panes
 * they read in.
 *
 * Unlike an InputLayer this claims nothing while the keyboard is elsewhere: the
 * scope is where the keyboard is, never what is on screen.
 */
interface FocusScope
{
}
