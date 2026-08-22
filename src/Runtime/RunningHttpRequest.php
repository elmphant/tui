<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Elmphant\Tui\Message\HttpResultMsg;
use Elmphant\Tui\Message\Message;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * @internal
 */
final class RunningHttpRequest implements RunningProcess
{
    private bool $finished = false;
    private string $body = '';

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ResponseInterface $response,
        private readonly ?EffectId $requestId,
        private readonly int $maxBodyBytes = 8_388_608,
        /**
         * @var Closure(Message): Message|null
         */
        private readonly ?Closure $wrap = null,
    ) {
    }

    public function pump(): array
    {
        if ($this->finished) {
            return [];
        }

        try {
            foreach ($this->client->stream([$this->response], 0) as $chunk) {
                if ($chunk->isTimeout()) {
                    return [];
                }

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
}
