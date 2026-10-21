<?php

namespace Studeo\Modular\Console\Actions;

use RuntimeException;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\DataObjects\DtoKind;

/**
 * Decides, from the normalised name alone, whether a DTO is an Input or an Output.
 * Nothing is ever appended: a name with neither suffix is an error.
 */
final class ResolveDtoKindAction
{
    public function __construct(
        private readonly NameSanitizer $sanitizer,
    ) {}

    public function execute(string $name): DtoKind
    {
        $segments = explode('/', $this->sanitizer->classPath($name, 'DTO'));

        $last = end($segments);
        $kind = DtoKind::forClassName($last) ?? throw new RuntimeException($this->message($name));

        $this->sanitizer->ensureStartsWithLetter($last, $name, 'DTO');

        return $kind;
    }

    public function forms(): string
    {
        return 'Valid forms: CreatePostInput (request data, an InputData) or PostData (response data, an OutputData).';
    }

    private function message(string $name): string
    {
        return "DTO name [{$name}] must end in Input or Data; nothing was created. {$this->forms()}";
    }
}
