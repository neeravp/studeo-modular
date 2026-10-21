<?php

namespace Studeo\Modular\Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use ReflectionClass;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Runs a command against a one-module skeleton with every process faked: the module Blog, the
 * processes the command starts recorded, the frontend skeleton compared before and after, and
 * the Prompts static state restored on tear down.
 */
class InstallerCommandDriver
{
    public readonly string $rootModulesPath;

    public readonly ModuleDefinition $module;

    public ?string $root = null;

    public bool $fails = false;

    /** @var array<int, string> */
    protected array $resolved = [];

    /** @var array<int, array{0: mixed, 1: string|null}> */
    private array $processes = [];

    /** @var array<string, string> */
    private readonly array $skeleton;

    /**
     * @param  array<int, class-string>  $logged  classes whose resolution is logged
     */
    public function __construct(Application $app, array $logged = [])
    {
        $this->rootModulesPath = config('modular.root_path');
        File::deleteDirectory($this->rootModulesPath);
        File::ensureDirectoryExists($this->rootModulesPath);
        $this->module = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootModulesPath.'/Blog');
        $app->make(CreateModuleAction::class)->execute($this->module);
        $this->skeleton = skeletonFrontendSnapshot();
        Process::fake(fn (PendingProcess $process) => $this->recordProcess($process));

        foreach ($logged as $class) {
            $app->resolving($class, function () use ($class): void {
                $this->resolved[] = 'resolve:'.class_basename($class);
            });
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, string>  $answers
     * @return array<string, mixed>
     */
    public function run(string $command, array $input, array $answers = [], bool $interactive = true): array
    {
        [$bodies, $digests] = $this->replacements();
        $this->resolved = [];
        $tester = new CommandTester(Artisan::all()[$command]);
        $tester->setInputs($answers);
        $status = $tester->execute($input, ['interactive' => $interactive, 'capture_stderr_separately' => true]);
        $clean = fn (string $text): string => str_replace(array_merge([$this->module->path, base_path(), $this->root ?? '<none>'], $bodies), array_merge(['<module>', '<base>', '<root>'], $digests), $text);

        return [
            'status' => $status,
            'stdout' => $clean($tester->getDisplay()),
            'stderr' => $clean($tester->getErrorOutput()),
            'answered' => ftell($tester->getInput()->getStream()),
            'resolved' => $this->resolved,
            'processes' => array_map(fn (array $process): array => [$process[0], $clean($process[1])], $this->processes),
            ...$this->extras(),
        ];
    }

    public function tearDown(bool $cleanInstallerRoots = true): void
    {
        expect(skeletonFrontendSnapshot())->toBe($this->skeleton);
        File::deleteDirectory($this->rootModulesPath);

        if ($cleanInstallerRoots) {
            cleanInstallerRoots();
        }

        $prompt = new ReflectionClass(Prompt::class);
        $prompt->getProperty('shouldFallback')->setValue(null, false);
        $prompt->getProperty('fallbacks')->setValue(null, []);
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>} file bodies to hide and the digests that replace them
     */
    protected function replacements(): array
    {
        return [[], []];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extras(): array
    {
        return [];
    }

    private function recordProcess(PendingProcess $process): mixed
    {
        $this->processes[] = [$process->command, $process->path];

        return Process::result(exitCode: $this->fails ? 1 : 0, errorOutput: $this->fails ? 'failed' : '');
    }
}
