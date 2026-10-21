<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->module = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootModulesPath.'/Blog');
    app(CreateModuleAction::class)->execute($this->module);
    $this->writeEnumFixture($this->module, 'PostStatus');

    Artisan::call('module:schema', ['name' => 'Post', '--module' => 'Blog', '--fields' => 'slug:string,title:string:nullable,status:enum(PostStatus):default(draft),published_at:datetime:nullable,author_id:foreignId:context']);
    Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-seeder' => true, '--schema' => '@posts']);

    $this->input = $this->module->path.'/Data/PostInput.php';
    $this->output = $this->module->path.'/Data/PostData.php';
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

function dtosGenerated(): void
{
    Artisan::call('module:dto', ['name' => 'PostInput', '--module' => 'Blog', '--schema' => '@posts']);
    Artisan::call('module:dto', ['name' => 'PostData', '--module' => 'Blog', '--schema' => '@posts']);
}

function dtoCheck(array $extra = []): array
{
    $exitCode = Artisan::call('module:schema:check', ['name' => 'Post', '--module' => 'Blog', ...$extra]);

    return [$exitCode, Artisan::output()];
}

function dtoEdit(string $file, string $search, string $replace): void
{
    $content = File::get($file);

    expect($content)->toContain($search);

    File::put($file, str_replace($search, $replace, $content));
}

it('is clean right after the DTOs are generated', function () {
    dtosGenerated();

    [$exitCode, $output] = dtoCheck();

    expect($exitCode)->toBe(0)->and($output)->toContain('Schema [posts]: no drift.')->not->toContain('Data/');
});

it('does not check, or call unverifiable, a DTO that does not exist', function () {
    [$exitCode, $output] = dtoCheck();

    expect($exitCode)->toBe(0)->and($output)->not->toContain('unverifiable')->not->toContain('Data/');

    Artisan::call('module:dto', ['name' => 'PostInput', '--module' => 'Blog', '--schema' => '@posts']);

    expect(dtoCheck()[0])->toBe(0);
});

it('reports a property the file lacks, one it adds, and a changed type', function () {
    dtosGenerated();
    dtoEdit($this->input, "        public string \$slug,\n", '');
    dtoEdit($this->input, '        public ?string $title = null,', "        public ?string \$title = null,\n        public int \$extra = 0,");
    dtoEdit($this->output, 'public string $slug,', 'public int $slug,');

    [$exitCode, $output] = dtoCheck();

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('Data/PostInput.php properties schema has [slug]; file lacks it')
        ->toContain('Data/PostInput.php properties file has [extra]; schema lacks it')
        ->toContain('Data/PostData.php properties [slug] differs: schema string; file int')
        ->toContain('Schema [posts]: 3 differences.');
});

it('does not count the order of the parameters, or how a type is spelled, as drift', function () {
    dtosGenerated();
    $content = File::get($this->input);
    preg_match('/protected function __construct\(\n(.*?)\n    \) \{\}/s', $content, $match);
    $lines = array_reverse(preg_split('/\n(?=        (?:#\[|public ))/', $match[1]));
    File::put($this->input, str_replace($match[1], implode("\n", $lines), $content));
    dtoEdit($this->output, 'public ?CarbonImmutable $publishedAt', 'public ?\\Carbon\\CarbonImmutable $publishedAt');

    [$exitCode, $output] = dtoCheck();

    expect(File::get($this->input))->not->toBe($content)->and($exitCode)->toBe(0)->and($output)->not->toContain('properties');
});

it('does not count code outside the constructor as drift', function () {
    dtosGenerated();
    dtoEdit($this->input, '    public static function rules(): array', "    public function shout(): string\n    {\n        return strtoupper(\$this->slug);\n    }\n\n    public static function rules(): array");

    expect(dtoCheck()[0])->toBe(0);
});

it('reports a DTO that exists but cannot be read as unverifiable and exits 1', function () {
    dtosGenerated();
    File::put($this->input, "<?php\n\nnamespace Studeo\\Blog\\Data;\n\nfinal readonly class PostInput {}\n");

    [$exitCode, $output] = dtoCheck();

    expect($exitCode)->toBe(1)->and($output)->toContain('Data/PostInput.php properties unverifiable');
});

it('skips the DTO section with --except=dto', function () {
    dtosGenerated();
    dtoEdit($this->input, "        public string \$slug,\n", '');

    [$exitCode, $output] = dtoCheck(['--except' => 'dto']);

    expect($exitCode)->toBe(0)->and($output)->not->toContain('Data/');
    expect(dtoCheck()[0])->toBe(1);
});
