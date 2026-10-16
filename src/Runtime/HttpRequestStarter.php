<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd\HttpCmd;
use Elmphant\Tui\Core\Message;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Makes the request a command asked for. Throws where it cannot, so what a
 * failure to reach the network is worth saying stays with the caller.
 *
 * @internal
 */
final class HttpRequestStarter
{
    public function __construct(private ?HttpClientInterface $client = null)
    {
    }

    /**
     * @param Closure(Message):Message|null $wrap
     */
    public function start(HttpCmd $command, ?Closure $wrap): RunningProcess
    {
        $client = $this->client ??= self::defaultClient();

        // Read as it arrives unless the caller wants the whole body at once: a
        // buffered response cannot report progress.
        $options = $command->options;
        $options['buffer'] ??= false;

        if ($command->headers !== []) {
            $options['headers'] = $command->headers;
        }
        if ($command->body !== null) {
            $options['body'] = $command->body;
        }

        return new RunningHttpRequest(
            $client,
            $client->request($command->method, $command->url, $options),
            $command->id,
            $command->maxBodyBytes,
            $wrap,
            self::idleTimeoutOf($options),
        );
    }

    /**
     * The package requires the client contracts — the interfaces — and not an
     * implementation, so an application that never makes a request installs no
     * HTTP client at all.
     */
    private static function defaultClient(): HttpClientInterface
    {
        if (!class_exists(HttpClient::class)) {
            throw new RuntimeException('symfony/http-client is required for Cmd::http(). Install it via: composer require symfony/http-client');
        }

        return HttpClient::create();
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function idleTimeoutOf(array $options): ?float
    {
        return is_numeric($options['timeout'] ?? null) ? (float)$options['timeout'] : null;
    }
}
