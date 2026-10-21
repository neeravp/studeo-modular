<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\ViteStatus;

final class ScaffoldInertiaFrontendAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly StubRenderer $stubRenderer,
        private readonly ArtifactWriter $artifactWriter,
        private readonly ?string $root = null,
    ) {}

    /**
     * What a scaffold would write, by path relative to the project, without writing it.
     *
     * @return array<string, string>
     */
    public function plan(ViteStatus $status): array
    {
        $stubs = [
            ViteStatus::ROOT_VIEW => 'inertia.root-view.stub',
            $status->viteConfigPath => 'vite.config.stub',
            $status->appJsPath => 'inertia.app.stub',
        ];

        return array_map(
            fn (string $path): string => $this->files->get($this->stubRenderer->path($stubs[$path])),
            array_combine(array_keys($status->pendingWrites()), array_keys($status->pendingWrites())),
        );
    }

    /**
     * Writes the planned files and returns their paths. Existing custom files are never in the plan.
     *
     * @return array<int, string>
     */
    public function execute(ViteStatus $status): array
    {
        $written = [];

        foreach ($this->plan($status) as $path => $contents) {
            $this->artifactWriter->write($this->absolute($path), $contents);
            $written[] = $path;
        }

        return $written;
    }

    private function absolute(string $path): string
    {
        return ($this->root ?? base_path()).'/'.$path;
    }
}
