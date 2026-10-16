<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Message;

use Closure;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Elmphant\Tui\Message\HttpResultMsg;
use Elmphant\Tui\Message\ProcessFinishedMsg;
use Elmphant\Tui\Message\ProcessOutputMsg;
use Elmphant\Tui\Message\ProcessResultMsg;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ComesFromAnEffectTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(?EffectId): (ErrorMsg|HttpResultMsg|ProcessFinishedMsg|ProcessOutputMsg|ProcessResultMsg)}>
     */
    public static function effectResults(): iterable
    {
        yield 'error' => [static fn (?EffectId $id) => new ErrorMsg(new RuntimeException('x'), ErrorSource::Process, $id)];
        yield 'http' => [static fn (?EffectId $id) => new HttpResultMsg(200, 'ok', effectId: $id)];
        yield 'output' => [static fn (?EffectId $id) => new ProcessOutputMsg('line', effectId: $id)];
        yield 'result' => [static fn (?EffectId $id) => new ProcessResultMsg('', '', 0, true, $id)];
        yield 'finished' => [static fn (?EffectId $id) => new ProcessFinishedMsg(0, true, $id)];
    }

    /**
     * @param Closure(?EffectId): (ErrorMsg|HttpResultMsg|ProcessFinishedMsg|ProcessOutputMsg|ProcessResultMsg) $make
     */
    #[Test]
    #[DataProvider('effectResults')]
    public function aResultSaysWhichEffectItCameFromByIdOrByName(Closure $make): void
    {
        $message = $make(EffectId::named('load'));

        self::assertTrue($message->isFrom('load'));
        self::assertTrue($message->isFrom(EffectId::named('load')));
        self::assertFalse($message->isFrom('save'));
        self::assertFalse($make(null)->isFrom('load'), 'a result with no id came from no named effect');
    }
}
