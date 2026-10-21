<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\DataObjects\ViteStatus;
use Studeo\Modular\Enums\FrontendFileState;

final class CheckViteConfigurationAction
{
    private const DEFAULT_APP_JS = ['', "import './bootstrap';", 'import "./bootstrap";'];

    private const FOREIGN_PLUGINS = ['@vitejs/plugin-vue', 'unplugin-vue-components', '@vitejs/plugin-react'];

    public function __construct(
        private readonly Filesystem $files,
        private readonly ?string $root = null,
    ) {}

    public function execute(): ViteStatus
    {
        $appJs = $this->existing(['resources/js/app.ts', 'resources/js/app.js']);
        $vite = $this->existing(['vite.config.ts', 'vite.config.js']);
        $typeScript = $this->files->exists($this->root().'/tsconfig.json');

        return new ViteStatus(
            appJs: $this->appJsState($appJs),
            appJsPath: $appJs ?? ($typeScript ? 'resources/js/app.ts' : 'resources/js/app.js'),
            appJsExists: $appJs !== null,
            viteConfig: $this->viteConfigState($vite),
            viteConfigPath: $vite ?? ($typeScript ? 'vite.config.ts' : 'vite.config.js'),
            viteConfigExists: $vite !== null,
            rootViewExists: $this->files->exists($this->root().'/'.ViteStatus::ROOT_VIEW),
        );
    }

    private function appJsState(?string $path): FrontendFileState
    {
        $content = $path === null ? '' : trim($this->files->get($this->root().'/'.$path));

        return match (true) {
            str_contains($content, 'modules/**/resources/js/Pages'), str_contains($content, 'modules/**/Pages') => FrontendFileState::Configured,
            in_array($content, self::DEFAULT_APP_JS, true) => FrontendFileState::Scaffoldable,
            default => FrontendFileState::Custom,
        };
    }

    private function viteConfigState(?string $path): FrontendFileState
    {
        $content = $path === null ? '' : $this->files->get($this->root().'/'.$path);

        return match (true) {
            str_contains($content, 'modules/*/resources/js/Components'), str_contains($content, 'modules/**/Components') => FrontendFileState::Configured,
            $this->hasForeignPlugin($content) => FrontendFileState::Custom,
            default => FrontendFileState::Scaffoldable,
        };
    }

    private function hasForeignPlugin(string $content): bool
    {
        return array_filter(self::FOREIGN_PLUGINS, fn (string $plugin): bool => str_contains($content, $plugin)) !== [];
    }

    /**
     * @param  array<int, string>  $candidates
     */
    private function existing(array $candidates): ?string
    {
        $found = array_filter($candidates, fn (string $candidate): bool => $this->files->exists($this->root().'/'.$candidate));

        return array_shift($found);
    }

    private function root(): string
    {
        return $this->root ?? base_path();
    }
}
