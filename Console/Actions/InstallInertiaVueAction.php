<?php

namespace Studeo\Modular\Console\Actions;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Studeo\Modular\DataObjects\InertiaVueStatus;

final class InstallInertiaVueAction
{
    private const NODE_PACKAGES = ['vue@^3.5', '@inertiajs/vue3@^3.0', '@vitejs/plugin-vue', 'unplugin-vue-components'];

    /**
     * @param  (Closure(): void)|null  $nodeValidator  throws when Node.js cannot run the installer
     */
    public function __construct(
        private readonly ?string $root = null,
        private readonly ?Closure $nodeValidator = null,
    ) {}

    /**
     * The commands that install what is missing, in the order they run.
     *
     * @return array<int, array<int, string>>
     */
    public function commands(InertiaVueStatus $status): array
    {
        return array_values(array_filter([
            $status->isMissing(InertiaVueStatus::SERVER) ? ['composer', 'require', InertiaVueStatus::SERVER.':^3.0'] : null,
            $this->needsNodePackages($status) ? $this->nodeCommand($status->packageManager) : null,
        ]));
    }

    /**
     * The commands that move an unsupported install to the supported major.
     *
     * @return array<int, array<int, string>>
     */
    public function upgradeCommands(InertiaVueStatus $status): array
    {
        $unsupported = $status->unsupported();

        return array_values(array_filter([
            in_array(InertiaVueStatus::SERVER, $unsupported, true) ? ['composer', 'require', InertiaVueStatus::SERVER.':^3.0'] : null,
            in_array(InertiaVueStatus::CLIENT, $unsupported, true) ? $this->nodeCommand($status->packageManager, [InertiaVueStatus::CLIENT.'@^3.0']) : null,
        ]));
    }

    public function execute(InertiaVueStatus $status): void
    {
        $this->validateNode();

        foreach ($this->commands($status) as $command) {
            $result = Process::path($this->root ?? base_path())->timeout(300)->run($command);

            $this->ensureSucceeded($result);
        }
    }

    private function needsNodePackages(InertiaVueStatus $status): bool
    {
        return array_diff($status->missing, [InertiaVueStatus::SERVER]) !== [];
    }

    /**
     * @param  array<int, string>|null  $packages
     * @return array<int, string>
     */
    private function nodeCommand(string $packageManager, ?array $packages = null): array
    {
        $packages ??= self::NODE_PACKAGES;

        return in_array($packageManager, ['yarn', 'pnpm', 'bun'], true)
            ? [$packageManager, 'add', ...$packages]
            : ['npm', 'install', ...$packages];
    }

    private function ensureSucceeded(ProcessResult $result): void
    {
        if (! $result->successful()) {
            throw new RuntimeException('Failed to install Inertia and Vue: '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    private function validateNode(): void
    {
        if ($this->nodeValidator !== null) {
            ($this->nodeValidator)();

            return;
        }

        $process = Process::path($this->root ?? base_path())->run(['node', '-v']);

        if (! $process->successful()) {
            throw new RuntimeException("Node.js is required to install and bundle Vue components, but was not found in your PATH.\nInstall Node.js 20+ from https://nodejs.org or with your version manager (nvm, fnm, brew).");
        }

        $this->ensureSupportedNode(trim($process->output()));
    }

    private function ensureSupportedNode(string $raw): void
    {
        if (version_compare(ltrim($raw, 'v'), '20.0.0', '<')) {
            throw new RuntimeException("Node.js [{$raw}] is installed, but Node.js 20.0.0+ is required for modern Vite and Vue 3.5.\nUpgrade your Node version (for example `nvm install 20 && nvm use 20`).");
        }
    }
}
