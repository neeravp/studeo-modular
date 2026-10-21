<?php

use Studeo\Modular\Console\Actions\CheckLivewireAction;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

it('reports Livewire as missing', function () {
    $status = (new CheckLivewireAction(fn (): ?string => null))->execute();

    expect($status->isInstalled())->toBeFalse()->and($status->major)->toBeNull();
});

it('reports the installed major', function () {
    $status = (new CheckLivewireAction(fn (): ?string => 'v4.1.2'))->execute();

    expect($status->isInstalled())->toBeTrue()->and($status->major)->toBe(4);
});

it('installed but with an unreadable version has no major', function () {
    $status = (new CheckLivewireAction(fn (): ?string => 'dev-main'))->execute();

    expect($status->isInstalled())->toBeTrue()->and($status->major)->toBeNull();
});

it('finds the Livewire this package is tested against', function () {
    $status = (new CheckLivewireAction)->execute();

    expect($status->isInstalled())->toBeTrue()->and($status->major)->toBe(4);
});
