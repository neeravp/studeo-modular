<?php

namespace Studeo\Modular\Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Laravel\Prompts\Prompt;
use ReflectionClass;
use Studeo\Modular\Console\Actions\DiscoverModulesAction;
use Studeo\Modular\Console\Actions\ResolveModuleAction;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Runs one registered command the way a terminal would: stdout and stderr kept apart, the answers
 * fed through the input stream, and the actions the command resolves logged in order.
 */
final class CommandDriver
{
    /** @var array<int, string> */
    private array $resolved = [];

    /**
     * @param  array<int, class-string>  $alsoLogged  further classes whose resolution is logged, after the two module actions
     */
    public function __construct(Application $app, private readonly string $modulesRoot, array $alsoLogged = [])
    {
        foreach ([DiscoverModulesAction::class, ResolveModuleAction::class, ...$alsoLogged] as $class) {
            $app->resolving($class, function () use ($class): void {
                $this->resolved[] = 'resolve:'.class_basename($class);
            });
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, string>  $answers
     * @return array{status: int, stdout: string, stderr: string, answered: int|false, resolved: array<int, string>}
     */
    public function run(string $command, array $input, array $answers = [], bool $interactive = true): array
    {
        $this->resolved = [];
        $tester = new CommandTester(Artisan::all()[$command]);
        $tester->setInputs($answers);
        $status = $tester->execute($input, ['interactive' => $interactive, 'capture_stderr_separately' => true]);

        return [
            'status' => $status,
            'stdout' => $this->normalise($tester->getDisplay()),
            'stderr' => $this->normalise($tester->getErrorOutput()),
            'answered' => ftell($tester->getInput()->getStream()),
            'resolved' => $this->resolved,
        ];
    }

    /**
     * Paths are shortened, and the dotted leaders of a select list are cut to three dots so that
     * the terminal width does not matter.
     */
    public function normalise(string $text): string
    {
        $shortened = str_replace([$this->modulesRoot, base_path()], ['<modules>', '<base>'], $text);

        return preg_replace('/ \.{3,} /', ' ... ', $shortened);
    }

    public function resetPrompts(): void
    {
        $prompt = new ReflectionClass(Prompt::class);
        $prompt->getProperty('shouldFallback')->setValue(null, false);
        $prompt->getProperty('fallbacks')->setValue(null, []);
    }
}
