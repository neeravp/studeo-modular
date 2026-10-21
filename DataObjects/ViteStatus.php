<?php

namespace Studeo\Modular\DataObjects;

use Studeo\Modular\Enums\FrontendFileState;

final readonly class ViteStatus
{
    public const ROOT_VIEW = 'resources/views/app.blade.php';

    public function __construct(
        public FrontendFileState $appJs,
        public string $appJsPath,
        public bool $appJsExists,
        public FrontendFileState $viteConfig,
        public string $viteConfigPath,
        public bool $viteConfigExists,
        public bool $rootViewExists,
    ) {}

    public function isConfigured(): bool
    {
        return $this->appJs === FrontendFileState::Configured
            && $this->viteConfig === FrontendFileState::Configured
            && $this->rootViewExists;
    }

    /**
     * The root files a scaffold would write, by path relative to the project, and whether it
     * would create or replace each.
     *
     * @return array<string, string>
     */
    public function pendingWrites(): array
    {
        return array_filter([
            self::ROOT_VIEW => $this->rootViewExists ? null : 'create',
            $this->viteConfigPath => $this->viteConfig === FrontendFileState::Scaffoldable ? $this->verb($this->viteConfigExists) : null,
            $this->appJsPath => $this->appJs === FrontendFileState::Scaffoldable ? $this->verb($this->appJsExists) : null,
        ]);
    }

    /**
     * Existing files the user has already customised, which a scaffold never touches.
     *
     * @return array<int, string>
     */
    public function customFiles(): array
    {
        return array_keys(array_filter([
            $this->viteConfigPath => $this->viteConfig === FrontendFileState::Custom,
            $this->appJsPath => $this->appJs === FrontendFileState::Custom,
        ]));
    }

    private function verb(bool $exists): string
    {
        return $exists ? 'replace' : 'create';
    }
}
