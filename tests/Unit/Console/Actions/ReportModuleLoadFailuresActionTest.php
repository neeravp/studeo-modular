<?php

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Event;
use Studeo\Modular\Console\Actions\ReportModuleLoadFailuresAction;
use Studeo\Modular\Exceptions\ModuleLoadException;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

uses(TestCase::class);

function moduleLoadFailure(string $module): ModuleLoadException
{
    return new ModuleLoadException($module, "Module [{$module}] failed to load: boom");
}

function dispatchCommandStarting(int $verbosity = OutputInterface::VERBOSITY_NORMAL): BufferedOutput
{
    $output = new BufferedOutput($verbosity);
    Event::dispatch(new CommandStarting('list', new ArrayInput([]), $output));

    return $output;
}

function reporterWith(object $test, ?ExceptionHandler $handler = null): ReportModuleLoadFailuresAction
{
    return new ReportModuleLoadFailuresAction(
        app('events'),
        $handler ?? Mockery::mock(ExceptionHandler::class),
        app('config'),
    );
}

it('warns before every artisan command in the console, including the failure message', function () {
    reporterWith($this)->execute(['Account' => moduleLoadFailure('Account')], runningInConsole: true);

    expect(dispatchCommandStarting()->fetch())->toContain('Module [Account] failed to load: boom')
        ->and(dispatchCommandStarting()->fetch())->toContain('Module [Account] failed to load: boom');
});

it('does not warn in quiet mode', function () {
    reporterWith($this)->execute(['Account' => moduleLoadFailure('Account')], runningInConsole: true);

    expect(dispatchCommandStarting(OutputInterface::VERBOSITY_QUIET)->fetch())->toBe('');
});

it('never throws or reports in the console, even in debug mode', function () {
    config()->set('app.debug', true);
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->never();

    reporterWith($this, $handler)->execute(['Account' => moduleLoadFailure('Account')], runningInConsole: true);

    expect(dispatchCommandStarting()->fetch())->toContain('failed to load');
});

it('reports every failure for HTTP requests when debug is off', function () {
    config()->set('app.debug', false);
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->twice();

    reporterWith($this, $handler)->execute(
        ['Account' => moduleLoadFailure('Account'), 'Billing' => moduleLoadFailure('Billing')],
        runningInConsole: false,
    );
});

it('throws the first failure for HTTP requests in debug mode and reports the rest', function () {
    config()->set('app.debug', true);
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->once()->with(Mockery::on(fn ($e) => $e->module === 'Billing'));

    try {
        reporterWith($this, $handler)->execute(
            ['Account' => moduleLoadFailure('Account'), 'Billing' => moduleLoadFailure('Billing')],
            runningInConsole: false,
        );
        $this->fail('ModuleLoadException was not thrown');
    } catch (ModuleLoadException $exception) {
        expect($exception->module)->toBe('Account');
    }
});

it('does nothing when there are no failures', function () {
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->never();

    reporterWith($this, $handler)->execute([], runningInConsole: false);
    reporterWith($this, $handler)->execute([], runningInConsole: true);

    expect(dispatchCommandStarting()->fetch())->toBe('');
});
