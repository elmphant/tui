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
        $command = Cmd::http('https://example.test/')->withOptions(['timeout' => 0]);
        $messages = (new HttpRequestStarter($this->idleClient()))->start($command, null)->pump();

        self::assertCount(1, $messages);
        self::assertInstanceOf(ErrorMsg::class, $messages[0]);
    }

    #[Test]
    public function anIdleRequestWithoutATimeoutKeepsWaiting(): void
    {
        $command = Cmd::http('https://example.test/');
        $request = (new HttpRequestStarter($this->idleClient()))->start($command, null);

        self::assertSame([[], true], [$request->pump(), $request->isRunning()]);
    }

    #[Test]
    public function aTimeoutWrittenAsANumericStringCountsAsWell(): void
    {
        $command = Cmd::http('https://example.test/')->withOptions(['timeout' => '0']);
        $messages = (new HttpRequestStarter($this->idleClient()))->start($command, null)->pump();

        self::assertInstanceOf(ErrorMsg::class, $messages[0] ?? null);
    }

    private function idleClient(): HttpClientInterface
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($this->createMock(ResponseInterface::class));
        $client->method('stream')->willReturnCallback(
            static fn (): ResponseStream => new ResponseStream((static function (): Generator {
                yield new ErrorChunk(0, 'Idle timeout reached for "https://example.test".');
            })()),
        );

        return $client;
    }
}
