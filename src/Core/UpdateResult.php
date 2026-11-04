<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Elmphant\Tui\Support\Cloneable;

/**
 * @template-covariant TModel of Reducer
 */
final readonly class UpdateResult
{
    use Cloneable;

    /**
     * @param TModel $model
     */
    public function __construct(
        public Reducer $model,
        public ?Cmd $command = null,
    ) {
    }

    /**
     * @return self<TModel>
     */
    public function withCommand(?Cmd $command): self
    {
        return $this->cloneWith(command: $command);
    }

    /**
     * @template TNext of Reducer
     *
     * @param callable(TModel): TNext $transform
     *
     * @return self<TNext>
     */
    public function mapModel(callable $transform): self
    {
        $model = $transform($this->model);

        return $model === $this->model
            ? $this
            : new self($model, $this->command);
    }

    /**
     * @param callable(Cmd): Cmd $transform
     *
     * @return self<TModel>
     */
    public function mapCommand(callable $transform): self
    {
        if ($this->command === null) {
            return $this;
        }

        $command = $transform($this->command);

        return $command === $this->command
            ? $this
            : new self($this->model, $command);
    }
}
