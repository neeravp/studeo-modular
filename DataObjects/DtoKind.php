<?php

namespace Studeo\Modular\DataObjects;

/**
 * The kind of DTO a name asks for. The name suffix is the only thing that decides it.
 */
enum DtoKind: string
{
    case Input = 'Input';
    case Output = 'Data';

    /**
     * The kind for a class name, or null when it has neither suffix. A name that is
     * only the suffix ("Input", "Data") names nothing, so it has no kind either.
     */
    public static function forClassName(string $className): ?self
    {
        $matching = array_filter(self::cases(), fn (self $kind): bool => self::endsWithSuffix($className, $kind));

        return array_shift($matching);
    }

    public function stub(): string
    {
        return match ($this) {
            self::Input => 'dto.input.stub',
            self::Output => 'dto.output.stub',
        };
    }

    private static function endsWithSuffix(string $className, self $kind): bool
    {
        return strlen($className) > strlen($kind->value) && str_ends_with($className, $kind->value);
    }
}
