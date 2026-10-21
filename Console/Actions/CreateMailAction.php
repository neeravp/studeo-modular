<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\MailGenerationOptions;
use Studeo\Modular\DataObjects\MailGenerationResult;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;
use Studeo\Modular\Support\PhpImports;

final class CreateMailAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly NameSanitizer $sanitizer,
        private readonly StubRenderer $stubRenderer,
        private readonly ModuleGuard $moduleGuard,
        private readonly ArtifactWriter $artifactWriter,
    ) {}

    public function execute(
        ModuleDefinition $module,
        string $name,
        ?MailGenerationOptions $options = null,
    ): MailGenerationResult {
        $options ??= new MailGenerationOptions;

        $this->moduleGuard->ensureExists($module);

        $mailName = $this->sanitizer->className($name, 'mail');
        $this->artifactWriter->ensureDoesNotExist($this->mailPath($module, $mailName), 'Mail', $mailName, $module);

        $viewDefinition = $this->resolveViewDefinition($mailName, $options);

        $mailPath = $this->createMailFile($module, $mailName, $viewDefinition, $options);
        $viewPath = $this->createViewFileIfRequested($module, $viewDefinition);

        return new MailGenerationResult(
            mailPath: $mailPath,
            viewPath: $viewPath,
        );
    }

    /**
     * @return array{parameter: string, name: string, create: bool}
     */
    private function resolveViewDefinition(string $mailName, MailGenerationOptions $options): array
    {
        $defaultView = 'mail.'.Str::kebab($this->resolveBaseName($mailName));

        return match (true) {
            $options->markdown !== null => $this->buildViewDefinition('markdown', $options->markdown, $defaultView, true),
            $options->view !== null => $this->buildViewDefinition('view', $options->view, $defaultView, true),
            default => $this->buildViewDefinition('view', null, $defaultView, false),
        };
    }

    /**
     * @return array{parameter: string, name: string, create: bool}
     */
    private function buildViewDefinition(string $parameter, ?string $view, string $defaultView, bool $create): array
    {
        return [
            'parameter' => $parameter,
            'name' => $view !== null && trim($view) !== '' ? trim($view) : $defaultView,
            'create' => $create,
        ];
    }

    /**
     * @param  array{parameter: string, name: string, create: bool}  $viewDefinition
     */
    private function createMailFile(
        ModuleDefinition $module,
        string $mailName,
        array $viewDefinition,
        MailGenerationOptions $options,
    ): string {
        $path = $this->mailPath($module, $mailName);
        $this->files->ensureDirectoryExists(dirname($path));

        $content = $this->buildMailContent($module, $mailName, $viewDefinition, $options);

        $this->files->put($path, $content);

        return $path;
    }

    /**
     * @param  array{parameter: string, name: string, create: bool}  $viewDefinition
     */
    private function buildMailContent(
        ModuleDefinition $module,
        string $mailName,
        array $viewDefinition,
        MailGenerationOptions $options,
    ): string {
        $namespace = $module->namespace.'\\Mail';
        $imports = new PhpImports($namespace, $mailName);
        /** @var array<string, string> $names */
        $names = array_map($imports->name(...), ['mailable' => Mailable::class, 'envelope' => Envelope::class, 'content' => Content::class, 'attachment' => Attachment::class, 'queueable' => Queueable::class, 'serializes' => SerializesModels::class, ...($options->queued ? ['shouldQueue' => ShouldQueue::class] : [])]);
        $replacements = [
            ...$names,
            'namespace' => $namespace,
            'class' => $mailName,
            'implements' => $options->queued ? ' implements '.$names['shouldQueue'] : '',
            'subject' => $this->buildSubject($mailName),
            'contentParameter' => $viewDefinition['parameter'],
            'view' => ModuleManifest::keyFor($module->name)."::{$viewDefinition['name']}",
            'imports' => $imports->statements(),
        ];

        return str_replace(
            array_map(fn (string $key): string => '{{ '.$key.' }}', array_keys($replacements)),
            array_values($replacements),
            $this->files->get($this->stubRenderer->path('Mail.php.stub')),
        );
    }

    private function buildSubject(string $mailName): string
    {
        return Str::headline($this->resolveBaseName($mailName));
    }

    private function resolveBaseName(string $mailName): string
    {
        return Str::endsWith($mailName, 'Mail') && $mailName !== 'Mail'
            ? Str::beforeLast($mailName, 'Mail')
            : $mailName;
    }

    /**
     * @param  array{parameter: string, name: string, create: bool}  $viewDefinition
     */
    private function createViewFileIfRequested(ModuleDefinition $module, array $viewDefinition): ?string
    {
        if (! $viewDefinition['create']) {
            return null;
        }

        $path = $this->viewPath($module, $viewDefinition['name']);
        $this->files->ensureDirectoryExists(dirname($path));

        $stubFile = $viewDefinition['parameter'] === 'markdown'
            ? 'mail-markdown.blade.php.stub'
            : 'mail-view.blade.php.stub';

        $stub = $this->files->get($this->stubRenderer->path($stubFile));

        $this->files->put($path, $stub);

        return $path;
    }

    private function viewPath(ModuleDefinition $module, string $viewName): string
    {
        $relativePath = str_replace('.', DIRECTORY_SEPARATOR, $viewName).'.blade.php';

        return $module->path.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.$relativePath;
    }

    private function mailPath(ModuleDefinition $module, string $mailName): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'Mail'.DIRECTORY_SEPARATOR."{$mailName}.php";
    }
}
