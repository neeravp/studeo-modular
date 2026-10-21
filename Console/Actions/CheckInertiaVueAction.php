<?php

namespace Studeo\Modular\Console\Actions;

use Closure;
use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\DataObjects\InertiaVueStatus;
use Studeo\Modular\Support\MajorVersion;

final class CheckInertiaVueAction
{
    /**
     * @param  (Closure(): ?string)|null  $serverVersion  the installed inertia-laravel version, null when absent
     */
    public function __construct(
        private readonly Filesystem $files,
        private readonly ?string $root = null,
        private readonly ?Closure $serverVersion = null,
    ) {}

    public function execute(): InertiaVueStatus
    {
        $manifest = $this->manifest();
        $declared = [...($manifest['dependencies'] ?? []), ...($manifest['devDependencies'] ?? [])];
        $server = $this->server();
        $client = $this->clientVersion($declared);

        return new InertiaVueStatus(
            serverMajor: MajorVersion::of($server),
            clientMajor: MajorVersion::of($client),
            missing: $this->missing($manifest !== null, $server !== null, $declared),
            hasPackageJson: $manifest !== null,
            packageManager: $this->packageManager(),
        );
    }

    /**
     * @param  array<string, string>  $declared
     * @return array<int, string>
     */
    private function missing(bool $hasPackageJson, bool $hasServer, array $declared): array
    {
        return array_keys(array_filter([
            'package.json' => ! $hasPackageJson,
            InertiaVueStatus::SERVER => ! $hasServer,
            'vue' => ! isset($declared['vue']),
            InertiaVueStatus::CLIENT => ! isset($declared[InertiaVueStatus::CLIENT]),
        ]));
    }

    private function server(): ?string
    {
        if ($this->serverVersion !== null) {
            return ($this->serverVersion)();
        }

        if (InstalledVersions::isInstalled(InertiaVueStatus::SERVER)) {
            return InstalledVersions::getPrettyVersion(InertiaVueStatus::SERVER) ?? 'unknown';
        }

        return class_exists('Inertia\\Inertia') ? 'unknown' : null;
    }

    /**
     * The installed client version when node_modules has one, the declared range otherwise.
     *
     * @param  array<string, string>  $declared
     */
    private function clientVersion(array $declared): ?string
    {
        $installed = $this->read($this->root().'/node_modules/'.InertiaVueStatus::CLIENT.'/package.json');

        return $installed['version'] ?? $declared[InertiaVueStatus::CLIENT] ?? null;
    }

    /**
     * @return array<string, mixed>|null null when there is no readable package.json
     */
    private function manifest(): ?array
    {
        return $this->read($this->root().'/package.json') ?? ($this->files->exists($this->root().'/package.json') ? [] : null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $path): ?array
    {
        $decoded = $this->files->exists($path) ? json_decode($this->files->get($path), true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    private function packageManager(): string
    {
        return match (true) {
            $this->files->exists($this->root().'/pnpm-lock.yaml') => 'pnpm',
            $this->files->exists($this->root().'/yarn.lock') => 'yarn',
            $this->files->exists($this->root().'/bun.lockb') => 'bun',
            default => 'npm',
        };
    }

    private function root(): string
    {
        return $this->root ?? base_path();
    }
}
