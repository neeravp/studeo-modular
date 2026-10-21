<?php

namespace Studeo\Modular\DataObjects;

/**
 * The Form a single-file component owns: its class, its file and the other files of the module
 * that name it. A Form that other files name is shared in practice and stays where it is.
 */
final readonly class LivewireComponentForm
{
    /**
     * @param  array<int, string>  $references  real paths of the other files that name the Form
     */
    public function __construct(
        public string $class,
        public string $path,
        public array $references = [],
    ) {}

    public function shared(): bool
    {
        return $this->references !== [];
    }
}
