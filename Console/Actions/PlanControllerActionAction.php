<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Studeo\Modular\DataObjects\ControllerActionPlan;
use Studeo\Modular\DataObjects\ControllerGenerationOptions;
use Studeo\Modular\DataObjects\FormActionPlan;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Support\RoutePath;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Decides, before anything is written, what an Action controller calls: --action names an Action of
 * the module (short, studly or a path inside Actions/, never a class name). Without --schema the
 * Action and its paired Input must exist; with it, they are planned as for a form and generated
 * when missing. A #[SystemAction] is refused, each #[Context] field needs --context=field=actor,
 * and --url is checked.
 */
final class PlanControllerActionAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly PlanFormActionAction $actions,
        private readonly ParseSchemaAction $schemas,
    ) {}

    /**
     * @throws RuntimeException
     */
    public function execute(ModuleDefinition $module, ControllerGenerationOptions $options): ControllerActionPlan
    {
        $action = $this->refuseClassName($module, (string) $options->action);
        $schema = $options->schema === null ? null : $this->schemas->execute($options->schema, $module)->forModule($module->namespace);
        $plan = $schema === null ? $this->existing($module, $action) : $this->actions->execute($module, $schema, $action);

        $this->actions->checkContext($options->context, $plan->context);
        $url = $options->url === null ? null : RoutePath::check($options->url, '--url=/'.($schema->table ?? 'posts'));

        return new ControllerActionPlan($plan, $schema, $url);
    }

    private function refuseClassName(ModuleDefinition $module, string $action): string
    {
        if (str_contains($action, '\\')) {
            throw new RuntimeException("Action [{$action}] is a class name, and an Action must be one of module [{$module->name}]: pass its name or its path inside Actions/, e.g. CreatePost or Posts/CreatePost. {$this->known($module)}");
        }

        return $action;
    }

    /**
     * @throws RuntimeException
     */
    private function existing(ModuleDefinition $module, string $action): FormActionPlan
    {
        $plan = $this->actions->existing($module, $action);

        if (! $plan->actionExists) {
            throw new RuntimeException("Action [{$plan->actionName}] is not an Action of module [{$module->name}]. {$this->known($module)} Pass --schema to generate it.");
        }

        if (! $plan->inputExists) {
            throw new RuntimeException("Action [{$plan->actionName}] exists but its paired Input [Data/{$plan->inputName}.php] does not. Pass --schema to generate it.");
        }

        return $plan;
    }

    private function known(ModuleDefinition $module): string
    {
        $directory = $module->path.'/Actions';
        $files = $this->files->isDirectory($directory) ? $this->files->allFiles($directory) : [];
        $actions = array_map(fn (SplFileInfo $file): string => str_replace('\\', '/', substr($file->getRelativePathname(), 0, -4)), array_filter($files, fn (SplFileInfo $file): bool => $file->getExtension() === 'php'));
        sort($actions);

        return $actions === [] ? 'The module has no Actions yet.' : "The module's Actions: ".implode(', ', $actions).'.';
    }
}
