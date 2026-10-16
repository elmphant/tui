<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\HttpResultMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Runtime\EffectOutputLimitExceeded;
use Elmphant\Tui\Runtime\RunningHttpRequest;
use Elmphant\Tui\Tests\Support\MessageEnvelope;
use Generator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class RunningHttpRequestTest extends TestCase
{
    #[Test]
    public function pumpWrapsSuccessMessages(): void
    {
        $response = new MockResponse('body', ['http_code' => 201]);
        $client = new MockHttpClient($response);
        $response = $client->request('GET', 'https://example.test');
        $wrap = static fn (Message $message): Message => new MessageEnvelope($message);
        $request = new RunningHttpRequest($client, $response, null, wrap: $wrap);

        $messages = $request->pump();

        self::assertCount(1, $messages);
        self::assertInstanceOf(MessageEnvelope::class, $messages[0]);
        self::assertInstanceOf(HttpResultMsg::class, $messages[0]->inner);
        self::assertSame(201, $messages[0]->inner->statusCode);
    }

    #[Test]
    public function pumpCatchesStreamException(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $client->method('stream')
            ->willThrowException(new RuntimeException('connection reset'));

        $wrap = static fn (Message $message): Message => new MessageEnvelope($message);
        $request = new RunningHttpRequest($client, $response, EffectId::named('test-id'), wrap: $wrap);
        $messages = $request->pump();

        self::assertCount(1, $messages);
        self::assertInstanceOf(MessageEnvelope::class, $messages[0]);
        self::assertInstanceOf(ErrorMsg::class, $messages[0]->inner);
        self::assertSame('connection reset', $messages[0]->inner->error->getMessage());
        self::assertFalse($request->isRunning());
    }

    #[Test]
    public function pumpCancelsAResponseThatExceedsItsBodyLimit(): void
    {
        $response = new MockResponse('12345');
        $client = new MockHttpClient($response);
        $response = $client->request('GET', 'https://example.test');

        $request = new RunningHttpRequest($client, $response, EffectId::named('large-http'), 4);
        $messages = $request->pump();

        self::assertCount(1, $messages);
        self::assertInstanceOf(ErrorMsg::class, $messages[0]);
        self::assertInstanceOf(EffectOutputLimitExceeded::class, $messages[0]->error);
        self::assertSame('large-http', $messages[0]->effectId?->value);
    }

    #[Test]
    public function pumpStreamsWithAZeroSecondTimeout(): void
    {
        $capturedTimeout = null;
        $client = $this->createMock(HttpClientInterface::class);
        $client->method('stream')->willReturnCallback(
            static function (iterable $responses, ?float $timeout) use (&$capturedTimeout): ResponseStream {
                $capturedTimeout = $timeout;

                return new ResponseStream((static function (): Generator {
                    yield from [];
                })());
            },
        );

        $request = new RunningHttpRequest($client, $this->createMock(ResponseInterface::class), null);

        self::assertSame([], $request->pump());
        self::assertSame(0.0, $capturedTimeout);
        self::assertTrue($request->isRunning());
    }

    #[Test]
    public function anIdleChunkPastTheDeadlineResolvesTheRequestWithAnError(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->method('stream')->willReturnCallback(
            static fn (): ResponseStream => new ResponseStream((static function (): Generator {
                yield new ErrorChunk(0, 'Idle timeout reached for "https://example.test".');
            })()),
        );
        $response = $this->createMock(ResponseInterface::class);
        $response->expects(self::once())->method('cancel');

        $request = new RunningHttpRequest(
            $client,
            $response,
            EffectId::named('slow-http'),
            timeoutSeconds: 0.0,
        );
        $messages = $request->pump();

        self::assertCount(1, $messages);
        self::assertInstanceOf(ErrorMsg::class, $messages[0]);
        self::assertSame('slow-http', $messages[0]->effectId?->value);
        self::assertFalse($request->isRunning());
    }

    #[Test]
    public function aResponseThatKeepsSendingOutlivesItsIdleTimeout(): void
    {
        $pumps = 0;
        $client = $this->createMock(HttpClientInterface::class);
        $client->method('stream')->willReturnCallback(
            static function () use (&$pumps): ResponseStream {
                ++$pumps;

                return new ResponseStream((static function () use ($pumps): Generator {
                    yield $pumps === 1
                        ? new DataChunk(0, 'part')
                        : new ErrorChunk(4, 'Idle timeout reached for "https://example.test".');
                })());
            },
        );

        $request = new RunningHttpRequest(
            $client,
            $this->createMock(ResponseInterface::class),
            EffectId::named('download'),
            timeoutSeconds: 0.1,
        );
        usleep(150_000);
        $request->pump();

        self::assertSame([], $request->pump());
        self::assertTrue($request->isRunning());
    }

    #[Test]
    public function pumpAccumulatesChunkedBodyContent(): void
    {
        $body = (static function (): Generator {
            yield 'ab';
            yield 'cd';
        })();
        $client = new MockHttpClient(new MockResponse($body));
        $response = $client->request('GET', 'https://example.test');

        $request = new RunningHttpRequest($client, $response, null);
        $messages = $request->pump();

        self::assertCount(1, $messages);
        self::assertInstanceOf(HttpResultMsg::class, $messages[0]);
        self::assertSame('abcd', $messages[0]->body);
        self::assertFalse($request->isRunning());
    }

    #[Test]
    public function pumpAllowsABodyExactlyAtTheLimit(): void
    {
        $client = new MockHttpClient(new MockResponse('1234'));
        $response = $client->request('GET', 'https://example.test');

        $request = new RunningHttpRequest($client, $response, null, 4);
        $messages = $request->pump();

        self::assertCount(1, $messages);
        self::assertInstanceOf(HttpResultMsg::class, $messages[0]);
        self::assertSame('1234', $messages[0]->body);
    }

    #[Test]
    public function pumpStopsTheRequestWhenTheBodyLimitIsExceeded(): void
    {
        $client = new MockHttpClient(new MockResponse('12345'));
        $response = $client->request('GET', 'https://example.test');

        $request = new RunningHttpRequest($client, $response, null, 4);
        $messages = $request->pump();

        self::assertCount(1, $messages);
        self::assertInstanceOf(ErrorMsg::class, $messages[0]);
        self::assertFalse($request->isRunning());
        self::assertTrue($response->getInfo('canceled'));
    }

    #[Test]
    public function pumpDefaultsToAnEightMebibyteBodyLimit(): void
    {
        $atLimitClient = new MockHttpClient(new MockResponse(str_repeat('x', 8_388_608)));
        $atLimitResponse = $atLimitClient->request('GET', 'https://example.test');
        $atLimitMessages = (new RunningHttpRequest($atLimitClient, $atLimitResponse, null))->pump();

        $overLimitClient = new MockHttpClient(new MockResponse(str_repeat('x', 8_388_609)));
        $overLimitResponse = $overLimitClient->request('GET', 'https://example.test');
        $overLimitMessages = (new RunningHttpRequest($overLimitClient, $overLimitResponse, null))->pump();

        self::assertCount(1, $atLimitMessages);
        self::assertInstanceOf(HttpResultMsg::class, $atLimitMessages[0]);
        self::assertCount(1, $overLimitMessages);
        self::assertInstanceOf(ErrorMsg::class, $overLimitMessages[0]);
        self::assertInstanceOf(EffectOutputLimitExceeded::class, $overLimitMessages[0]->error);
        self::assertSame(8_388_608, $overLimitMessages[0]->error->limitBytes);
    }

    #[Test]
    public function pumpKeepsHeadersFromFailedResponses(): void
    {
        $client = new MockHttpClient(new MockResponse('missing', [
            'http_code' => 404,
            'response_headers' => ['content-type' => 'text/plain'],
        ]));
        $response = $client->request('GET', 'https://example.test');
        self::assertSame(404, $response->getStatusCode());

        $request = new RunningHttpRequest($client, $response, null);
        $messages = $request->pump();

        self::assertCount(1, $messages);
        self::assertInstanceOf(HttpResultMsg::class, $messages[0]);
        self::assertSame(404, $messages[0]->statusCode);
        self::assertSame('missing', $messages[0]->body);
        self::assertSame(['text/plain'], $messages[0]->headers['content-type'] ?? null);
    }

    #[Test]
    public function stopCancelsTheResponseOnce(): void
    {
        $client = new MockHttpClient(new MockResponse('body'));
        $response = $client->request('GET', 'https://example.test');

        $request = new RunningHttpRequest($client, $response, null);
        $request->stop();
        $request->stop();

        self::assertFalse($request->isRunning());
        self::assertTrue($response->getInfo('canceled'));
        self::assertSame([], $request->pump());
    }
}
