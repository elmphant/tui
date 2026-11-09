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
     * Asked after the child is stored, with what the child answered: its outcome, or the input it took.
     */
    public function answeredFor(int $child, Message $answered): ChildAnswer;
}
