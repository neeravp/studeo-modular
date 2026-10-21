<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Studeo\Modular\Console\Generation\ArtifactWriter;
use Studeo\Modular\Console\Generation\ModuleGuard;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;
use Studeo\Modular\DataObjects\NotificationGenerationOptions;
use Studeo\Modular\DataObjects\NotificationGenerationResult;
use Studeo\Modular\Support\PhpImports;

final class CreateNotificationAction
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
        ?NotificationGenerationOptions $options = null,
    ): NotificationGenerationResult {
        $options ??= new NotificationGenerationOptions;

        $this->moduleGuard->ensureExists($module);

        $notificationName = $this->sanitizer->className($name, 'notification');
        $this->artifactWriter->ensureDoesNotExist($this->notificationPath($module, $notificationName), 'Notification', $notificationName, $module);

        $viewName = $this->resolveMarkdownView($notificationName, $options->markdown);

        $notificationPath = $this->createNotificationFile($module, $notificationName, $viewName, $options);
        $viewPath = $this->createMarkdownViewFileIfRequested($module, $viewName);

        return new NotificationGenerationResult(
            notificationPath: $notificationPath,
            viewPath: $viewPath,
        );
    }

    private function resolveMarkdownView(string $notificationName, ?string $markdown): ?string
    {
        if ($markdown === null) {
            return null;
        }

        $trimmed = trim($markdown);

        if ($trimmed !== '') {
            return $trimmed;
        }

        return 'mail.'.Str::kebab($this->withoutNotificationSuffix($notificationName));
    }

    /**
     * The name without a trailing Notification, unless that would leave nothing.
     */
    private function withoutNotificationSuffix(string $notificationName): string
    {
        return Str::endsWith($notificationName, 'Notification') && $notificationName !== 'Notification'
            ? Str::beforeLast($notificationName, 'Notification')
            : $notificationName;
    }

    private function createNotificationFile(
        ModuleDefinition $module,
        string $notificationName,
        ?string $viewName,
        NotificationGenerationOptions $options,
    ): string {
        $path = $this->notificationPath($module, $notificationName);
        $this->files->ensureDirectoryExists(dirname($path));

        $content = $this->buildNotificationContent($module, $notificationName, $viewName, $options);

        $this->files->put($path, $content);

        return $path;
    }

    private function buildNotificationContent(
        ModuleDefinition $module,
        string $notificationName,
        ?string $viewName,
        NotificationGenerationOptions $options,
    ): string {
        $namespace = $module->namespace.'\\Notifications';
        $imports = new PhpImports($namespace, $notificationName);
        /** @var array<string, string> $names */
        $names = array_map($imports->name(...), ['notification' => Notification::class, 'mailMessage' => MailMessage::class, 'queueable' => Queueable::class, ...($options->queued ? ['shouldQueue' => ShouldQueue::class] : [])]);
        $replacements = [
            ...$names,
            'namespace' => $namespace,
            'class' => $notificationName,
            'implements' => $options->queued ? ' implements '.$names['shouldQueue'] : '',
            'toMailContent' => $this->buildToMailContent($module, $viewName, $names['mailMessage']),
            'imports' => $imports->statements(),
        ];

        return str_replace(
            array_map(fn (string $key): string => '{{ '.$key.' }}', array_keys($replacements)),
            array_values($replacements),
            $this->files->get($this->stubRenderer->path('Notification.php.stub')),
        );
    }

    private function buildToMailContent(ModuleDefinition $module, ?string $viewName, string $mailMessage): string
    {
        if ($viewName !== null) {
            $moduleKey = ModuleManifest::keyFor($module->name);

            return "return (new {$mailMessage})->markdown('{$moduleKey}::{$viewName}');";
        }

        return "return new {$mailMessage};";
    }

    private function createMarkdownViewFileIfRequested(ModuleDefinition $module, ?string $viewName): ?string
    {
        if ($viewName === null) {
            return null;
        }

        $path = $this->viewPath($module, $viewName);
        $this->files->ensureDirectoryExists(dirname($path));

        $stub = $this->files->get($this->stubRenderer->path('notification-markdown.blade.php.stub'));

        $this->files->put($path, $stub);

        return $path;
    }

    private function viewPath(ModuleDefinition $module, string $viewName): string
    {
        $relativePath = str_replace('.', DIRECTORY_SEPARATOR, $viewName).'.blade.php';

        return $module->path.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.$relativePath;
    }

    private function notificationPath(ModuleDefinition $module, string $notificationName): string
    {
        return $module->path.DIRECTORY_SEPARATOR.'Notifications'.DIRECTORY_SEPARATOR."{$notificationName}.php";
    }
}
