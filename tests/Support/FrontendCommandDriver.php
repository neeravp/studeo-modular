<?php

namespace Studeo\Modular\Tests\Support;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Studeo\Modular\Console\Actions\CheckInertiaVueAction;
use Studeo\Modular\Console\Actions\CheckViteConfigurationAction;
use Studeo\Modular\Console\Actions\InstallInertiaVueAction;
use Studeo\Modular\Console\Actions\ScaffoldInertiaFrontendAction;

/**
 * The installer driver for the Inertia and Vite characterizations: a frontend root bound on
 * demand, the scaffold bodies shortened to digests, and the root and the component file reported
 * after every run.
 */
final class FrontendCommandDriver extends InstallerCommandDriver
{
    public function __construct(private readonly Application $app)
    {
        parent::__construct($app, [CheckInertiaVueAction::class, InstallInertiaVueAction::class, CheckViteConfigurationAction::class, ScaffoldInertiaFrontendAction::class]);
    }

    public function bind(?string $root = null, ?string $serverVersion = '3.0.1', ?Closure $nodeValidator = null): void
    {
        $this->root = bindFrontendRoot($root, $serverVersion, $nodeValidator);
    }

    protected function replacements(): array
    {
        $bodies = $this->root === null ? [] : $this->app->make(ScaffoldInertiaFrontendAction::class)->plan($this->app->make(CheckViteConfigurationAction::class)->execute());
        $digests = array_map(fn (string $contents): string => '<contents '.substr(sha1($contents), 0, 8).'>', $bodies);

        return [array_values($bodies), array_values($digests)];
    }

    protected function extras(): array
    {
        return [
            'root' => $this->snapshotRoot(),
            'component' => is_file($this->module->path.'/resources/js/Components/PostCard.vue'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function snapshotRoot(): array
    {
        $files = [];

        foreach (frontendSnapshot($this->root) as $path => $hash) {
            $files[substr($path, strlen($this->root) + 1)] = $hash;
        }

        return $files;
    }
}
