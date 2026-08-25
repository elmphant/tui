<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Runtime\HttpRequestStarter;
use Generator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

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

    #[Test]
    public function aConfiguredTimeoutResolvesAnIdleRequestWithAnError(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($this->createMock(ResponseInterface::class));
        $client->method('stream')->willReturnCallback(
            static fn (): ResponseStream => new ResponseStream((static function (): Generator {
                yield new ErrorChunk(0, 'Idle timeout reached for "https://example.test".');
            })()),
        );

        $command = Cmd::http('https://example.test/', options: ['timeout' => 0]);
        $messages = (new HttpRequestStarter($client))->start($command, null)->pump();

        self::assertCount(1, $messages);
        self::assertInstanceOf(ErrorMsg::class, $messages[0]);
    }
}
