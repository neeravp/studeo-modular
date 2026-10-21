<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\DataObjects\ModuleManifest;

final class RenderModuleProviderAction
{
    public function __construct(
        private readonly Filesystem $files,
    ) {}

    public function execute(ModuleManifest $manifest): string
    {
        return str_replace(
            ['{moduleNamespace}', '{moduleName}'],
            [$manifest->namespace, $manifest->name],
            $this->files->get(__DIR__.'/../stubs/ServiceProvider.php.stub')
        );
    }
}
