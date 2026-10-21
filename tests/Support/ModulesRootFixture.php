<?php

namespace Studeo\Modular\Tests\Support;

use ArrayObject;
use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;

/**
 * An empty modules root with the command driver for it, the modules a case needs and the expected
 * result shape. Optionally the application's root composer.json is written fresh and restored on
 * tear down, with a recording Composer for the install and uninstall characterizations.
 */
final class ModulesRootFixture
{
    public const STUDLY = 'must be one StudlyCase segment of letters and digits, for example Account or BlogPosts.';

    public readonly string $rootPath;

    public readonly string $composerPath;

    public readonly ArrayObject $composerCalls;

    public int $exit = 0;

    public bool $throws = false;

    private readonly ?string $originalComposer;

    private ?CommandDriver $driver = null;

    /**
     * @param  array<int, string>  $resolved  the resolution log every outcome carries
     */
    public function __construct(private readonly array $resolved = [], private readonly bool $usesComposer = true)
    {
        $this->composerPath = base_path('composer.json');
        $this->originalComposer = File::exists($this->composerPath) ? File::get($this->composerPath) : null;
        $this->rootPath = config('modular.root_path');
        $this->composerCalls = new ArrayObject;
        File::deleteDirectory($this->rootPath);
        File::ensureDirectoryExists($this->rootPath);
    }

    /**
     * @param  array<string, string>  $psr4
     */
    public function writeRoot(array $psr4 = []): void
    {
        File::put($this->composerPath, json_encode([
            'name' => 'laravel/laravel',
            'autoload' => ['psr-4' => ['App\\' => 'app/'] + $psr4],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    public function writeInstalled(string ...$installed): void
    {
        $this->writeRoot(array_combine(
            array_map(fn (string $name): string => "Studeo\\{$name}\\", $installed),
            array_map(fn (string $name): string => "modules/{$name}/", $installed),
        ));
    }

    /**
     * @return array<int, string>
     */
    public function installedKeys(): array
    {
        return array_keys(json_decode(File::get($this->composerPath), true)['autoload']['psr-4']);
    }

    public function modules(string ...$names): void
    {
        foreach ($names as $name) {
            app(CreateModuleAction::class)->execute($this->definition($name));
        }
    }

    public function definition(string $name): ModuleDefinition
    {
        return new ModuleDefinition($name, "Studeo\\{$name}", "{$this->rootPath}/{$name}");
    }

    public function fakeComposer(): void
    {
        app()->instance(Composer::class, new class($this->composerCalls, $this) extends Composer
        {
            public function __construct(private readonly ArrayObject $calls, private readonly ModulesRootFixture $fixture) {}

            public function dumpAutoloads($extra = '', $path = null)
            {
                $this->calls[] = 'dumpAutoloads';

                return $this->fixture->throws ? throw new RuntimeException('composer is gone') : $this->fixture->exit;
            }
        });
    }

    /**
     * @return array{status: int, stdout: string, stderr: string, answered: int, resolved: array<int, string>}
     */
    public function outcome(int $status, string $stdout, int $answered = 0, string $stderr = ''): array
    {
        return [
            'status' => $status,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'answered' => $answered,
            'resolved' => $this->resolved,
        ];
    }

    public function spin(string $message): string
    {
        return "\e[?25l\n \e[36m⠶\e[39m {$message}\n\e[999D\e[2A\e[J\e[?25h";
    }

    public function spinAfterPrompt(string $message): string
    {
        return "\e[?25l \e[36m⠶\e[39m {$message}\n\e[999D\e[1A\e[J\e[?25h";
    }

    /**
     * @param  array<int, class-string>  $logged
     */
    public function driver(array $logged = []): CommandDriver
    {
        return $this->driver = new CommandDriver(app(), $this->rootPath, $logged);
    }

    public function tearDown(): void
    {
        File::deleteDirectory($this->rootPath);
        $this->driver?->resetPrompts();

        if ($this->usesComposer) {
            $this->restoreComposer();
        }
    }

    private function restoreComposer(): void
    {
        if ($this->originalComposer === null) {
            File::delete($this->composerPath);

            return;
        }

        File::put($this->composerPath, $this->originalComposer);
    }
}
