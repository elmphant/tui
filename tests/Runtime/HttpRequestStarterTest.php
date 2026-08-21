<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Runtime\HttpRequestStarter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Making the request a command asked for, on the terms it asked for.
 */
final class HttpRequestStarterTest extends TestCase
{
    #[Test]
    public function aRequestIsUnbufferedUnlessItAsksToBe(): void
    {
        $response = new MockResponse('ok');

        (new HttpRequestStarter(new MockHttpClient($response)))
            ->start(Cmd::http('https://example.test/'), null);

        self::assertFalse($response->getRequestOptions()['buffer'], 'streaming needs a response read as it arrives');
    }
}
