<?php

namespace Studeo\Modular\Tests\Support;

use Symfony\Component\Process\Process;

final class LivewireFormProcess
{
    /**
     * Boot an application with the real Livewire 4, the installed Blog module and an in-memory SQLite
     * database holding the generated articles table, then run the snippet. The snippet sees $app,
     * $outcome (a closure returning "ok" or the exception class and message) and $form (a fresh Form).
     *
     * @return mixed the decoded JSON the snippet echoed
     */
    public function run(string $modulesPath, string $snippet): mixed
    {
        $script = <<<'PHP'
            require $argv[1];
            spl_autoload_register(function (string $class) use ($argv): void {
                $file = $argv[2].'/Blog/'.str_replace('\\', '/', substr($class, strlen('Studeo\\Blog\\'))).'.php';
                if (str_starts_with($class, 'Studeo\\Blog\\') && is_file($file)) {
                    require $file;
                }
            });
            $app = Orchestra\Testbench\Foundation\Application::create();
            $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
            $app['config']->set('database.default', 'testing');
            $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
            $app['config']->set('modular.root_path', $argv[2]);
            $app['config']->set('modular.root_namespace', 'Studeo');
            $app->register(Livewire\LivewireServiceProvider::class);
            $app->register(Studeo\Modular\Providers\ModularServiceProvider::class);
            Illuminate\Support\Facades\Schema::create('users', fn ($table) => $table->id());
            Illuminate\Support\Facades\DB::table('users')->insert(['id' => 7]);
            (require glob($argv[2].'/Blog/database/migrations/*_create_articles_table.php')[0])->up();
            Illuminate\Support\Facades\Gate::define('create', fn () => true);
            $outcome = function (Closure $run) {
                try {
                    $run();

                    return 'ok';
                } catch (Throwable $exception) {
                    return [get_class($exception), $exception->getMessage()];
                }
            };
            $form = new Studeo\Blog\Livewire\Forms\CreateArticleForm(new class extends Livewire\Component {}, 'form');
            $valid = ['form.slug' => 'a', 'form.title' => 't', 'form.status' => 'published', 'form.price' => '9.99'];
            $fill = function ($testable, array $state) { foreach ($state as $key => $value) { $testable->set($key, $value); } return $testable; };
            PHP;

        $process = new Process([PHP_BINARY, '-r', $script."\n".$snippet, dirname(__DIR__, 2).'/vendor/autoload.php', $modulesPath]);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
