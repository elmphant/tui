<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Elmphant\Tui\Message\HttpResultMsg;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * @internal
 */
final class RunningHttpRequest implements RunningProcess
{
    private const NANOSECONDS_PER_SECOND = 1_000_000_000;

    private bool $finished = false;
    private string $body = '';
    private float $lastHeardAt;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ResponseInterface $response,
        private readonly ?EffectId $requestId,
        private readonly int $maxBodyBytes = 8_388_608,
        /**
         * @var Closure(Message): Message|null
         */
        private readonly ?Closure $wrap = null,
        private readonly ?float $timeoutSeconds = null,
    ) {
        $this->lastHeardAt = hrtime(true) / self::NANOSECONDS_PER_SECOND;
    }

    public function pump(): array
    {
        if ($this->finished) {
            return [];
        }

        try {
            foreach ($this->client->stream([$this->response], 0) as $chunk) {
                if ($chunk->isTimeout()) {
                    if ($this->idleTooLong()) {
                        $this->stop();

                        return [$this->map(new ErrorMsg(
                            new RuntimeException(sprintf('HTTP request timed out after %g seconds without data.', $this->timeoutSeconds)),
                            ErrorSource::Http,
                            $this->requestId,
                        ))];
                    }

                    return [];
                }

                if ($chunk->isFirst()) {
                    // Symfony throws on a 4xx or 5xx status as the stream goes on, unless the status was read first.
                    $this->response->getStatusCode();
                }

                $this->lastHeardAt = hrtime(true) / self::NANOSECONDS_PER_SECOND;
                $this->body .= $chunk->getContent();
                if (strlen($this->body) > $this->maxBodyBytes) {
                    $this->stop();

                    return [$this->map(new ErrorMsg(
                        new EffectOutputLimitExceeded($this->maxBodyBytes, 'HTTP response'),
                        ErrorSource::Http,
                        $this->requestId,
                    ))];
                }

                if ($chunk->isLast()) {
                    $this->finished = true;

                    return [$this->map(new HttpResultMsg(
                        $this->response->getStatusCode(),
                        $this->body,
                        $this->response->getHeaders(false),
                        $this->requestId,
                    ))];
                }
            }
        } catch (Throwable $error) {
            $this->finished = true;

            return [$this->map(new ErrorMsg($error, ErrorSource::Http, $this->requestId))];
        }

        return [];
    }

    public function map(Message $message): Message
    {
        return $this->wrap === null ? $message : ($this->wrap)($message);
    }

    public function askToStop(): void
    {
    }

    public function stop(): void
    {
        if (!$this->finished) {
            try {
                $this->response->cancel();
            } catch (Throwable) {
            }
            $this->finished = true;
        }
    }

    public function isRunning(): bool
    {
        return !$this->finished;
    }

    public function id(): ?EffectId
    {
        return $this->requestId;
    }

    public function secondsLeft(): ?float
    {
        if ($this->timeoutSeconds === null) {
            return null;
        }

        return $this->timeoutSeconds - (hrtime(true) / self::NANOSECONDS_PER_SECOND - $this->lastHeardAt);
    }

    private function idleTooLong(): bool
    {
        return ($this->secondsLeft() ?? 1.0) <= 0.0;
    }
}
