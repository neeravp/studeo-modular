<?php

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Support\ModuleCache;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/modular-cache-'.bin2hex(random_bytes(4));
    mkdir($this->directory);
    $this->path = $this->directory.'/modular.php';
    $this->cache = new ModuleCache($this->path, new Filesystem);
    $this->entry = [
        'listeners' => ['Studeo\\Billing\\Events\\Paid' => ['Studeo\\Billing\\Listeners\\Notify@handle']],
        'policies' => ['Studeo\\Billing\\Models\\Invoice' => 'Studeo\\Billing\\Policies\\InvoicePolicy'],
        'middleware' => ['billing.ensure-paid' => 'Studeo\\Billing\\Middleware\\EnsurePaid'],
    ];
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->directory);
});

function freshModuleCache(object $test): ModuleCache
{
    return new ModuleCache($test->path, new Filesystem);
}

it('round-trips an entry keyed by module key', function () {
    $this->cache->write(['billing' => $this->entry]);

    expect(freshModuleCache($this)->entryFor('billing'))->toBe($this->entry)
        ->and(freshModuleCache($this)->entryFor('other'))->toBeNull();
});

it('writes a PHP file holding a version and plain arrays and strings only', function () {
    $this->cache->write(['billing' => $this->entry]);

    $data = include $this->path;

    expect($data)->toBe(['version' => ModuleCache::VERSION, 'modules' => ['billing' => $this->entry]])
        ->and(file_get_contents($this->path))->not->toContain('Closure')->not->toContain('__set_state');
});

it('writes through a temp file in the same directory and leaves nothing behind', function () {
    $this->cache->write(['billing' => $this->entry]);
    $this->cache->write(['billing' => $this->entry]);

    expect(array_values(array_diff(scandir($this->directory), ['.', '..'])))->toBe(['modular.php']);
});

it('sees a rewrite made through the same instance', function () {
    $this->cache->write(['billing' => $this->entry]);
    $this->cache->entryFor('billing');
    $this->cache->write(['shop' => $this->entry]);

    expect($this->cache->entryFor('billing'))->toBeNull()
        ->and($this->cache->entryFor('shop'))->toBe($this->entry);
});

it('treats a missing file as absent', function () {
    expect($this->cache->entryFor('billing'))->toBeNull();
});

it('ignores a file with an unknown format version', function () {
    file_put_contents($this->path, '<?php return '.var_export(['version' => 999, 'modules' => ['billing' => $this->entry]], true).';');

    expect($this->cache->entryFor('billing'))->toBeNull();
});

it('ignores corrupt files without throwing or printing', function (string $contents) {
    file_put_contents($this->path, $contents);

    ob_start();
    $entry = $this->cache->entryFor('billing');
    $output = ob_get_clean();

    expect($entry)->toBeNull()->and($output)->toBe('');
})->with([
    'syntax error' => '<?php return [ "version" => ;',
    'truncated' => '<?php return [\'version\' => 1, \'modu',
    'not php' => 'plain text, not a cache',
    'not an array' => '<?php return 5;',
    'throws' => '<?php throw new RuntimeException("boom");',
    'empty' => '',
]);

it('ignores an entry that is not made of plain strings and arrays', function (array $entry) {
    file_put_contents($this->path, '<?php return '.var_export(['version' => 1, 'modules' => ['billing' => $entry]], true).';');

    expect($this->cache->entryFor('billing'))->toBeNull();
})->with([
    'listener handlers not a list' => [['listeners' => ['E' => 'H'], 'policies' => [], 'middleware' => []]],
    'policy not a string' => [['listeners' => [], 'policies' => ['M' => 5], 'middleware' => []]],
    'middleware list' => [['listeners' => [], 'policies' => [], 'middleware' => ['App\\A']]],
    'missing section' => [['listeners' => [], 'policies' => []]],
]);

it('clears the file and is silent and idempotent when it is missing', function () {
    $this->cache->write(['billing' => $this->entry]);

    $this->cache->clear();
    $this->cache->clear();

    expect($this->path)->not->toBeFile()->and($this->cache->entryFor('billing'))->toBeNull();
});
