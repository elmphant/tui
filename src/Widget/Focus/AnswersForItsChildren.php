<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Focus;

use Elmphant\Tui\Core\Message;

/**
 * A container that decides what the handlers bound to it hear of its children's answers. A form uses it to hold
 * back its submit button's press while a field has a problem.
 */
interface AnswersForItsChildren extends GraftsItsChildren
{
    /**
     * Asked when a child answers an outcome, before any handler hears it. The child's change is stored with the
     * container this answers.
     */
    public function answeredFor(int $child, Message $answered): ChildAnswer;
}
