<?php

namespace Studeo\Modular\DataObjects;

final readonly class InertiaVueStatus
{
    public const SERVER = 'inertiajs/inertia-laravel';

    public const CLIENT = '@inertiajs/vue3';

    public const SUPPORTED_MAJOR = 3;

    /**
     * @param  array<int, string>  $missing  'package.json', the server package, 'vue' and the client package, whichever are absent
     */
    public function __construct(
        public ?int $serverMajor,
        public ?int $clientMajor,
        public array $missing,
        public bool $hasPackageJson,
        public string $packageManager,
    ) {}

    public function isInstalled(): bool
    {
        return $this->missing === [];
    }

    public function isMissing(string $package): bool
    {
        return in_array($package, $this->missing, true);
    }

    public function isUnsupported(): bool
    {
        return $this->unsupported() !== [];
    }

    /**
     * The installed Inertia packages that are older than the supported major.
     *
     * @return array<int, string>
     */
    public function unsupported(): array
    {
        return array_keys(array_filter(
            [self::SERVER => $this->serverMajor, self::CLIENT => $this->clientMajor],
            fn (?int $major): bool => $major !== null && $major < self::SUPPORTED_MAJOR,
        ));
    }
}
