<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\DirectoryPruner;
use Studeo\Modular\DataObjects\LivewireComponentForm;
use Studeo\Modular\DataObjects\LivewireFormMove;
use Studeo\Modular\DataObjects\ModuleDefinition;

/**
 * Moves a Form with its component. plan() decides everything and throws before anything has moved;
 * execute() then writes the Form at its new path, removes the old one and rewrites the component's
 * own use and property lines.
 */
final class MoveLivewireFormAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly PlanLivewireFormAction $paths,
        private readonly DirectoryPruner $directoryPruner,
        private readonly ArtifactWriter $artifactWriter,
    ) {}

    /**
     * @throws RuntimeException when the target Form already exists
     */
    public function plan(ModuleDefinition $sourceModule, LivewireComponentForm $form, ModuleDefinition $targetModule, string $targetName): ?LivewireFormMove
    {
        $to = $this->paths->formPath($targetModule, $targetName);
        $toClass = $this->paths->formClass($targetModule, $targetName);

        if ($this->isTheFormItself($to, $form)) {
            return null;
        }

        $this->ensureTargetFormIsFree($to, $targetModule);

        return new LivewireFormMove($form->path, $to, $this->rewriteForm($this->files->get($form->path), $form->class, $toClass), $form->class, $toClass, $sourceModule->path.'/Livewire/Forms');
    }

    /**
     * @return array<string, string> the Form's old path => new path
     */
    public function execute(LivewireFormMove $move, string $componentPath): array
    {
        $this->artifactWriter->write($move->to, $move->content);
        $this->files->delete($move->from);
        $this->directoryPruner->pruneEmptyParentDirectories($move->from, $move->formsRoot);
        $this->files->put($componentPath, $this->rewriteComponent($this->files->get($componentPath), $move->fromClass, $move->toClass));

        return [$move->from => $move->to];
    }

    private function isTheFormItself(string $to, LivewireComponentForm $form): bool
    {
        return $this->files->isFile($to) && realpath($to) === realpath($form->path);
    }

    private function ensureTargetFormIsFree(string $to, ModuleDefinition $targetModule): void
    {
        if ($this->files->exists($to)) {
            throw new RuntimeException('Form ['.Str::after($to, $targetModule->path.'/')."] already exists in module [{$targetModule->name}]; nothing was moved.");
        }
    }

    private function rewriteForm(string $source, string $from, string $to): string
    {
        $source = (string) preg_replace('/^namespace [^;]+;/m', 'namespace '.Str::beforeLast($to, '\\').';', $source, 1);

        return (string) preg_replace('/\bclass '.preg_quote(class_basename($from), '/').'\b/', 'class '.class_basename($to), $source, 1);
    }

    private function rewriteComponent(string $source, string $from, string $to): string
    {
        $source = str_replace("use {$from};", "use {$to};", $source);

        return (string) preg_replace_callback(
            '/public\s+'.preg_quote(class_basename($from), '/').'(\s+\$form\b)/',
            fn (array $match): string => 'public '.class_basename($to).$match[1],
            $source,
        );
    }
}
