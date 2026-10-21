<?php

namespace Studeo\Modular\Exceptions;

use RuntimeException;
use Studeo\Modular\DataObjects\ModuleManifest;

final class InvalidModuleManifestException extends RuntimeException
{
    public static function missing(string $path): self
    {
        return new self("Module manifest not found: {$path}");
    }

    public static function legacy(string $path, ModuleManifest $expected): self
    {
        return new self(
            "This module still uses a composer.json manifest, which is no longer read.\n"
            ."Create {$path} with:\n\n".$expected->toJson()
        );
    }

    public static function invalid(string $path, string $reason): self
    {
        return new self("Invalid module manifest {$path}: {$reason}");
    }
}
