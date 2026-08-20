<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\Modal;
use Elmphant\Tui\Widget\Text;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Whether a modal is going away is a question about the message in hand. Kept as
 * a flag it needed someone to reset it, so a host that holds its dialog and shows
 * it a second time got one that had already been dismissed — a confirmation that
 * can only ever be asked for once.
 */
final class AModalAnswersWhetherAMessageDismissesItTest extends TestCase
{
    #[Test]
    public function havingBeenDismissedOnceDoesNotDismissItEverAfter(): void
    {
        $area = new Rect(0, 0, 40, 20);
        $modal = Modal::new('Support', Text::new('body'));
        $escape = new KeyMsg(Key::Escape);

        $shownAgain = $modal->update($escape, $area)->model;
        self::assertTrue($shownAgain->dismisses($escape, $area), 'precondition: escape is what closes it');

        self::assertFalse(
            $shownAgain->dismisses(new KeyMsg('x'), $area),
            'the answer follows the message in hand, not what happened to it before',
        );
    }
}
