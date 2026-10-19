<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Support;

use Elmphant\Tui\Text\CellMap;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\WrapCache;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * A test that finds its answer already cached does none of the work, so coverage
 * never credits it and Infection never runs it against the code it guards.
 */
final class ForgetStaticCaches implements Extension, PreparedSubscriber
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber($this);
    }

    public function notify(Prepared $event): void
    {
        WrapCache::clear();
        CellMap::clear();
        CharWidth::forgetMeasurements();
    }
}
