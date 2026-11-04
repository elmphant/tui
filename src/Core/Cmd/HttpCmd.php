<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\NamesItsEffect;
use Elmphant\Tui\Support\Cloneable;
use InvalidArgumentException;

final readonly class HttpCmd extends Cmd implements NamesItsEffect
{
    use Cloneable;

    /**
     * @internal build one with Cmd::http()
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $options
     */
    public function __construct(
        public string $url,
        public HttpMethod $method = HttpMethod::Get,
        public array $headers = [],
        public ?string $body = null,
        public ?EffectId $id = null,
        public array $options = [],
        public int $maxBodyBytes = 8_388_608,
    ) {
        if ($maxBodyBytes < 1) {
            throw new InvalidArgumentException('HttpCmd maxBodyBytes must be positive');
        }
    }

    public function effectId(): ?EffectId
    {
        return $this->id;
    }

    public function withUrl(string $url): self
    {
        return $this->cloneWith(url: $url);
    }

    public function withMethod(HttpMethod $method): self
    {
        return $this->cloneWith(method: $method);
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return $this->cloneWith(headers: $headers);
    }

    public function withBody(?string $body): self
    {
        return $this->cloneWith(body: $body);
    }

    public function withId(?EffectId $id): self
    {
        return $this->cloneWith(id: $id);
    }

    /**
     * @param array<string, mixed> $options passed to the HTTP client; past $options['timeout'] seconds without data the effect resolves as an ErrorMsg instead of idling forever, and $options['max_duration'] bounds the whole request
     */
    public function withOptions(array $options): self
    {
        return $this->cloneWith(options: $options);
    }

    public function withMaxBodyBytes(int $maxBodyBytes): self
    {
        return $this->cloneWith(maxBodyBytes: $maxBodyBytes);
    }
}
