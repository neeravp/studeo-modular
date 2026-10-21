<?php

use Illuminate\Contracts\Events\ShouldBeDiscovered;
use Illuminate\Foundation\Events\DiscoverEvents;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Support\ModuleListenerDiscovery;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->directory = config('modular.root_path').'/Disc';
    File::deleteDirectory(config('modular.root_path'));

    $this->writeModuleManifest($this->directory, 'Disc', 'Studeo\\Disc');
    $this->autoloadModule('Studeo\\Disc', $this->directory);

    $write = function (string $relative, string $contents) {
        File::ensureDirectoryExists(dirname($this->directory.'/'.$relative));
        File::put($this->directory.'/'.$relative, "<?php\n\n".$contents."\n");
    };

    $write('Events/OrderPlaced.php', "namespace Studeo\\Disc\\Events;\nclass OrderPlaced {}");
    $write('Events/OrderShipped.php', "namespace Studeo\\Disc\\Events;\nclass OrderShipped {}");
    $write('Listeners/Handles.php', "namespace Studeo\\Disc\\Listeners;\nuse Studeo\\Disc\\Events\\OrderPlaced;\nclass Handles { public function handle(OrderPlaced \$e): void {} public function handleLater(OrderPlaced \$e): void {} private function handleHidden(OrderPlaced \$e): void {} public function other(OrderPlaced \$e): void {} }");
    $write('Listeners/Invokes.php', "namespace Studeo\\Disc\\Listeners;\nuse Studeo\\Disc\\Events\\OrderShipped;\nclass Invokes { public function __invoke(OrderShipped \$e): void {} }");
    $write('Listeners/Nested/Deep.php', "namespace Studeo\\Disc\\Listeners\\Nested;\nuse Studeo\\Disc\\Events\\OrderShipped;\nclass Deep { public function handle(OrderShipped \$e): void {} }");
    $write('Listeners/Abstracted.php', "namespace Studeo\\Disc\\Listeners;\nuse Studeo\\Disc\\Events\\OrderPlaced;\nabstract class Abstracted { public function handle(OrderPlaced \$e): void {} }");
    $write('Listeners/Opted.php', "namespace Studeo\\Disc\\Listeners;\nuse Studeo\\Disc\\Events\\OrderPlaced;\nclass Opted implements \\Illuminate\\Contracts\\Events\\ShouldBeDiscovered { public static function shouldBeDiscovered(): bool { return false; } public function handle(OrderPlaced \$e): void {} }");
});

afterEach(function () {
    File::deleteDirectory(config('modular.root_path'));
});

it('discovers listeners the way Laravel does, with module namespaces', function () {
    $events = ModuleListenerDiscovery::within($this->directory.'/Listeners', $this->directory);

    // Laravel returns listeners in filesystem order; compare without depending on it.
    $events = array_map(function (array $listeners): array {
        sort($listeners);

        return $listeners;
    }, $events);
    ksort($events);

    expect($events)->toBe([
        'Studeo\\Disc\\Events\\OrderPlaced' => [
            'Studeo\\Disc\\Listeners\\Handles@handle',
            'Studeo\\Disc\\Listeners\\Handles@handleLater',
        ],
        'Studeo\\Disc\\Events\\OrderShipped' => [
            'Studeo\\Disc\\Listeners\\Invokes@__invoke',
            'Studeo\\Disc\\Listeners\\Nested\\Deep@handle',
        ],
    ]);
});

it('leaves Laravel global class name guessing untouched', function () {
    ModuleListenerDiscovery::within($this->directory.'/Listeners', $this->directory);

    $callback = new ReflectionProperty(DiscoverEvents::class, 'guessClassNamesUsingCallback');

    expect($callback->getValue())->toBeNull();
});

it('is guarded against Laravel changing the parts of DiscoverEvents it builds on', function () {
    foreach (['classFromFile', 'getListenerEvents'] as $method) {
        $reflection = new ReflectionMethod(DiscoverEvents::class, $method);

        expect($reflection->isStatic())->toBeTrue("DiscoverEvents::{$method} is no longer static")
            ->and($reflection->isProtected())->toBeTrue("DiscoverEvents::{$method} is no longer protected")
            ->and($reflection->getNumberOfParameters())->toBe(2, "DiscoverEvents::{$method} changed its parameters");
    }

    expect((new ReflectionMethod(ModuleListenerDiscovery::class, 'classFromFile'))->getDeclaringClass()->getName())
        ->toBe(ModuleListenerDiscovery::class)
        ->and(interface_exists(ShouldBeDiscovered::class))->toBeTrue();
});
