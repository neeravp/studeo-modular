<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->work = installerRoot([
        'testbench.yaml' => "providers:\n  - Studeo\\Modular\\Providers\\ModularServiceProvider\n  - Studeo\\Modular\\Tests\\CheckRules\\ConfigProvider\n",
        'vendor/autoload.php' => "<?php require '".dirname(__DIR__, 2)."/vendor/autoload.php';",
    ]);
    $this->rootPath = config('modular.root_path');
    File::deleteDirectory($this->rootPath);
    runOk($this->work, ['module:make', 'Enrollment']);
});

afterEach(function () {
    File::deleteDirectory($this->rootPath);
    cleanInstallerRoots();
});

it('prints the three findings of the before fixture and exits 1', function () {
    seedModule($this, 'Enrollment', ['Services/StripePaymentGateway.php' => (string) file_get_contents(__DIR__.'/../fixtures/cap1/StripePaymentGatewayBefore.php')]);

    $process = checkCommand($this->work, ['module:check', 'Enrollment']);
    $lines = array_filter(explode("\n", $process->getOutput()), fn (string $line): bool => str_contains($line, 'Services/StripePaymentGateway.php'));

    expect($process->getExitCode())->toBe(1, checkOutput($process))
        ->and(array_map(fn (string $line): string => substr($line, 0, strpos($line, ']') + 1), array_values($lines)))->toBe([
            'modules/Enrollment/Services/StripePaymentGateway.php:14 [condition-extraction]',
            'modules/Enrollment/Services/StripePaymentGateway.php:61 [condition-extraction]',
            'modules/Enrollment/Services/StripePaymentGateway.php:61 [nesting-depth]',
        ])
        ->and($process->getOutput())->toContain('3 errors');
});
