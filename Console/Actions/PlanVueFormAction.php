<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Support\Str;
use RuntimeException;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;
use Studeo\Modular\DataObjects\VueComponentOptions;
use Studeo\Modular\DataObjects\VueFormPlan;
use Studeo\Modular\Support\RoutePath;

/**
 * Decides, before anything is written, what a Vue form page needs: the schema, the Action it saves
 * through (--action), that Action's paired Input and the URL it posts to (--url). It throws on the
 * first problem and shows an example of the missing option, so a failed plan leaves the module
 * untouched. Anything that is not a form gets no plan, and refuses the form-only options.
 */
final class PlanVueFormAction
{
    public function __construct(private readonly PlanFormActionAction $actions) {}

    /**
     * @throws RuntimeException
     */
    public function execute(ModuleDefinition $module, ?SchemaDefinition $schema, VueComponentOptions $options): ?VueFormPlan
    {
        if (! $options->isForm()) {
            return $this->refuseFormOptions($options);
        }

        if ($schema === null) {
            throw new RuntimeException('A form needs a schema, which decides its fields. Pass --schema, e.g. --schema=@articles.');
        }

        $action = $options->action ?? throw new RuntimeException('A form saves through an Action. Pass --action, e.g. --action='.$this->actionExample($schema).'.');
        $url = $this->url($options->url, $schema);

        return new VueFormPlan($this->actions->execute($module, $schema, $action), $url);
    }

    private function refuseFormOptions(VueComponentOptions $options): null
    {
        $given = array_keys(array_filter(['--action' => $options->action, '--url' => $options->url], fn (?string $value): bool => $value !== null));

        if ($given !== []) {
            throw new RuntimeException('Only a form takes '.implode(' and ', $given).'. Add --form or --schema, or leave the option out.');
        }

        return null;
    }

    private function url(?string $url, SchemaDefinition $schema): string
    {
        $example = '--url=/'.($schema->table ?? 'posts');

        if ($url === null) {
            throw new RuntimeException("A form posts to a URL. Pass --url, e.g. {$example}.");
        }

        return RoutePath::check($url, $example);
    }

    private function actionExample(SchemaDefinition $schema): string
    {
        return 'Create'.Str::studly($schema->name ?? Str::singular($schema->table ?? 'post'));
    }
}
