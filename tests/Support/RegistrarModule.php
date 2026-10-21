<?php

namespace Studeo\Modular\Tests\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Studeo\Modular\Support\ModuleRegistrar;
use Studeo\Modular\Tests\TestCase;

final class RegistrarModule
{
    public function __construct(private readonly TestCase $test) {}

    public function file(string $directory, string $relative, string $contents): void
    {
        File::ensureDirectoryExists(dirname($directory.'/'.$relative));
        File::put($directory.'/'.$relative, "<?php\n\n".$contents."\n");
    }

    /**
     * Build a module with one artifact of every kind the registrar scans.
     */
    public function build(string $name = 'Reg', ?string $namespace = null): string
    {
        $namespace ??= "Studeo\\{$name}";
        $directory = $this->test->modulesPath.'/'.$name;
        $key = str($name)->kebab()->toString();

        $this->test->writeModuleManifest($directory, $name, $namespace);
        $this->test->autoloadModule($namespace, $directory);

        $this->file($directory, "config/{$key}.php", "return ['answer' => 42];");
        $this->file($directory, 'lang/en/messages.php', "return ['hi' => 'Hello'];");
        $this->file($directory, 'routes/web.php', "use Illuminate\\Support\\Facades\\Route;\nRoute::get('/{$key}-web', fn () => 'web')->name('{$key}.web');");
        $this->file($directory, 'routes/api.php', "use Illuminate\\Support\\Facades\\Route;\nRoute::get('/{$key}-api', fn () => 'api')->name('{$key}.api');");
        File::ensureDirectoryExists($directory.'/resources/views');
        File::put($directory.'/resources/views/hello.blade.php', 'hello');
        File::ensureDirectoryExists($directory.'/database/migrations');

        $this->file($directory, 'Commands/Ping.php', "namespace {$namespace}\\Commands;\nuse Illuminate\\Console\\Command;\nclass Ping extends Command { protected \$signature = '{$key}:ping'; public function handle(): int { return 0; } }");
        $this->file($directory, 'Commands/Nested/Pong.php', "namespace {$namespace}\\Commands\\Nested;\nuse Illuminate\\Console\\Command;\nclass Pong extends Command { protected \$signature = '{$key}:pong'; public function handle(): int { return 0; } }");
        $this->file($directory, 'Commands/Helper.php', "namespace {$namespace}\\Commands;\nclass Helper {}");
        $this->file($directory, 'Commands/BaseCommand.php', "namespace {$namespace}\\Commands;\nuse Illuminate\\Console\\Command;\nabstract class BaseCommand extends Command {}");

        $this->file($directory, 'Events/UserRegistered.php', "namespace {$namespace}\\Events;\nclass UserRegistered {}");
        $this->file($directory, 'Listeners/NotifyUser.php', "namespace {$namespace}\\Listeners;\nuse {$namespace}\\Events\\UserRegistered;\nclass NotifyUser { public function handle(UserRegistered \$event): void {} }");
        $this->file($directory, 'Listeners/Billing/ChargeCard.php', "namespace {$namespace}\\Listeners\\Billing;\nuse {$namespace}\\Events\\UserRegistered;\nclass ChargeCard { public function __invoke(UserRegistered \$event): void {} }");

        $this->file($directory, 'Models/Flight.php', "namespace {$namespace}\\Models;\nclass Flight {}");
        $this->file($directory, 'Models/Billing/Invoice.php', "namespace {$namespace}\\Models\\Billing;\nclass Invoice {}");
        $this->file($directory, 'Policies/FlightPolicy.php', "namespace {$namespace}\\Policies;\nclass FlightPolicy {}");
        $this->file($directory, 'Policies/Billing/InvoicePolicy.php', "namespace {$namespace}\\Policies\\Billing;\nclass InvoicePolicy {}");
        $this->file($directory, 'Policies/OrphanPolicy.php', "namespace {$namespace}\\Policies;\nclass OrphanPolicy {}");

        $this->file($directory, 'Middleware/EnsureActive.php', "namespace {$namespace}\\Middleware;\nclass EnsureActive { public function handle(\$request, \\Closure \$next) { return \$next(\$request); } }");
        $this->file($directory, 'Middleware/Api/ThrottleBurst.php', "namespace {$namespace}\\Middleware\\Api;\nclass ThrottleBurst { public function handle(\$request, \\Closure \$next) { return \$next(\$request); } }");

        return $directory;
    }

    public function registrar(string $directory): ModuleRegistrar
    {
        return ModuleRegistrar::for(new class(app()) extends ServiceProvider {}, $directory);
    }
}
