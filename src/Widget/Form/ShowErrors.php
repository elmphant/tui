<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Form;

/**
 * When a form starts saying what is wrong with a field.
 */
enum ShowErrors
{
    case AfterSubmit;
    case AfterLeaving;
}
