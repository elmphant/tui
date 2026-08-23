<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Message\Message;
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
     * @param Closure(Message): Message|null $wrap
     */
    public function start(Cmd\HttpCmd $command, ?Closure $wrap): RunningProcess
    {
        $client = $this->client ??= HttpClient::create();

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
        );
    }
}
