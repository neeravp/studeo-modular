<?php

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\DataObjects\LivewireComponentDefinition;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Support\LivewireComponentScanner;
use Studeo\Modular\Support\LivewirePaths;

const SCANNER_ZAP = "\u{26A1}";

beforeEach(function () {
    $this->root = realpath(sys_get_temp_dir()).'/modular-scanner-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/Blog/Livewire', 0777, true);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->root);
});

function scannerPut(object $test, string $relative, string $contents = '<div></div>'): void
{
    $path = $test->root.'/Blog/'.$relative;
    is_dir(dirname($path)) || mkdir(dirname($path), 0777, true);
    file_put_contents($path, $contents);
}

function scannerClass(string $name): string
{
    return "<?php\n\nnamespace Acme\\Blog\\Livewire;\n\nclass {$name} {}\n";
}

/**
 * @return array<string, string> alias => "type name target files", with the module root shown as ~
 */
function scannerResult(object $test): array
{
    $files = new Filesystem;
    $scanner = new LivewireComponentScanner($files, new LivewirePaths($files));
    $module = new ModuleDefinition(name: 'Blog', namespace: 'Acme\\Blog', path: $test->root.'/Blog');

    return array_map(
        fn (LivewireComponentDefinition $component): string => str_replace($test->root.'/Blog', '~', implode(' ', [$component->type, $component->name, $component->target, $component->path, implode(',', $component->files)])),
        $scanner->scan($module),
    );
}

test('a module with no Livewire directory has no components', function () {
    rmdir($this->root.'/Blog/Livewire');

    expect(scannerResult($this))->toBe([]);
});

test('an empty Livewire directory has no components', function () {
    expect(scannerResult($this))->toBe([]);
});

test('Forms and Attributes are reserved only as the first directory', function () {
    scannerPut($this, 'Livewire/Forms/PostForm.php', scannerClass('PostForm'));
    scannerPut($this, 'Livewire/Attributes/Audit.php', scannerClass('Audit'));
    scannerPut($this, 'Livewire/FormsExtra/Thing.php', scannerClass('Thing'));
    scannerPut($this, 'Livewire/Admin/Forms/Inner.php', scannerClass('Inner'));
    scannerPut($this, 'Livewire/Admin/Attributes/Deep.php', scannerClass('Deep'));

    expect(array_keys(scannerResult($this)))->toBe(['admin.attributes.deep', 'admin.forms.inner', 'forms-extra.thing']);
});

test('files ending in .test.php or Test.php are excluded, anywhere and by case', function () {
    scannerPut($this, 'Livewire/Counter.test.php', scannerClass('Counter'));
    scannerPut($this, 'Livewire/CounterTest.php', scannerClass('CounterTest'));
    scannerPut($this, 'Livewire/Admin/Table.test.php', scannerClass('Table'));
    scannerPut($this, 'Livewire/Latest.php', scannerClass('Latest'));
    scannerPut($this, 'Livewire/Other.TEST.php', scannerClass('Loud'));

    expect(array_keys(scannerResult($this)))->toBe(['latest', 'other.t-e-s-t']);
});

test('only .php and .blade.php files can be components', function () {
    foreach (['a.css', 'b.js', 'c.txt', 'd.phtml', 'e.PHP', 'f.php.bak', 'g.blade'] as $name) {
        scannerPut($this, "Livewire/{$name}", scannerClass('X'));
    }
    scannerPut($this, 'Livewire/Real.php', scannerClass('Real'));

    expect(array_keys(scannerResult($this)))->toBe(['real']);
});

test('hidden files and hidden directories are not scanned', function () {
    scannerPut($this, 'Livewire/.Hidden.php', scannerClass('Hidden'));
    scannerPut($this, 'Livewire/.cache/Inside.php', scannerClass('Inside'));
    scannerPut($this, 'Livewire/Shown.php', scannerClass('Shown'));

    expect(array_keys(scannerResult($this)))->toBe(['shown']);
});

test('a plain blade file is a single-file component and keeps its Test suffix check on the extension', function () {
    scannerPut($this, 'Livewire/Plain.blade.php');
    scannerPut($this, 'Livewire/WidgetTest.blade.php');

    expect(scannerResult($this))->toBe([
        'plain' => 'sfc Plain ~/Livewire/Plain.blade.php ~/Livewire/Plain.blade.php ~/Livewire/Plain.blade.php',
        'widget-test' => 'sfc WidgetTest ~/Livewire/WidgetTest.blade.php ~/Livewire/WidgetTest.blade.php ~/Livewire/WidgetTest.blade.php',
    ]);
});

test('a multi-file directory is a component only through its base file', function () {
    $zap = SCANNER_ZAP;
    scannerPut($this, "Livewire/{$zap}gauge/gauge.php", "<?php\n");
    scannerPut($this, "Livewire/{$zap}gauge/gauge.blade.php");
    scannerPut($this, "Livewire/{$zap}gauge/gauge.css");
    scannerPut($this, "Livewire/{$zap}lonely/lonely.blade.php");
    scannerPut($this, "Livewire/{$zap}lonely/lonely.css");
    scannerPut($this, "Livewire/{$zap}wrong/other.php", "<?php\n");
    scannerPut($this, "Livewire/{$zap}tested/tested.test.php", "<?php\n");

    expect(scannerResult($this))->toBe([
        'gauge' => "mfc gauge ~/Livewire/{$zap}gauge ~/Livewire/{$zap}gauge ~/Livewire/{$zap}gauge/gauge.blade.php,~/Livewire/{$zap}gauge/gauge.css,~/Livewire/{$zap}gauge/gauge.php",
    ]);
});

test('the base file must sit directly in the zap directory and carry its name', function () {
    $zap = SCANNER_ZAP;
    scannerPut($this, "Livewire/{$zap}deep/sub/deep.php", "<?php\n");
    scannerPut($this, "Livewire/{$zap}outer/{$zap}inner/inner.php", "<?php\n");
    scannerPut($this, "Livewire/{$zap}outer/{$zap}extra.blade.php");
    scannerPut($this, "Livewire/{$zap}case/CASE.php", "<?php\n");

    expect(scannerResult($this))->toBe([]);
});

test('the first zap directory decides, nesting and variation selectors are stripped', function () {
    $zap = SCANNER_ZAP;
    scannerPut($this, "Livewire/Admin/{$zap}inner/inner.php", "<?php\n");
    scannerPut($this, "Livewire/{$zap}\u{FE0F}vs/vs.php", "<?php\n");
    scannerPut($this, "Livewire/Plain{$zap}x/Marked.php", scannerClass('Marked'));

    expect(array_map(fn (string $line): string => strtok($line, ' ').' '.strtok(' '), scannerResult($this)))->toBe([
        'admin.inner' => 'mfc Admin/inner',
        "plain{$zap}x.marked" => "classic Plain{$zap}x/Marked",
        'vs' => 'mfc vs',
    ]);
});

test('classic files without a namespace or a class are skipped', function () {
    scannerPut($this, 'Livewire/NoNamespace.php', "<?php\n\nclass NoNamespace {}\n");
    scannerPut($this, 'Livewire/NoClass.php', "<?php\n\nnamespace Acme\\Blog\\Livewire;\n");
    scannerPut($this, 'Livewire/Good.php', scannerClass('Good'));

    expect(array_keys(scannerResult($this)))->toBe(['good']);
});

test('classic components list their view and feature test only when they exist', function () {
    scannerPut($this, 'Livewire/Bare.php', scannerClass('Bare'));
    scannerPut($this, 'Livewire/Full.php', scannerClass('Full'));
    scannerPut($this, 'resources/views/livewire/full.blade.php');
    scannerPut($this, 'tests/Feature/Livewire/FullTest.php', "<?php\n");
    scannerPut($this, 'Livewire/ViewOnly.php', scannerClass('ViewOnly'));
    scannerPut($this, 'resources/views/livewire/view-only.blade.php');

    expect(scannerResult($this))->toBe([
        'bare' => 'classic Bare Acme\\Blog\\Livewire\\Bare ~/Livewire/Bare.php ~/Livewire/Bare.php',
        'full' => 'classic Full Acme\\Blog\\Livewire\\Full ~/Livewire/Full.php ~/Livewire/Full.php,~/resources/views/livewire/full.blade.php,~/tests/Feature/Livewire/FullTest.php',
        'view-only' => 'classic ViewOnly Acme\\Blog\\Livewire\\ViewOnly ~/Livewire/ViewOnly.php ~/Livewire/ViewOnly.php,~/resources/views/livewire/view-only.blade.php',
    ]);
});

test('the first file in path order keeps an alias that several shapes share', function () {
    $zap = SCANNER_ZAP;
    scannerPut($this, 'Livewire/Dup.php', scannerClass('Dup'));
    scannerPut($this, "Livewire/{$zap}dup.blade.php");
    scannerPut($this, "Livewire/{$zap}dup/dup.php", "<?php\n");
    scannerPut($this, 'Livewire/Alpha.php', scannerClass('Alpha'));

    expect(array_map(fn (string $line): string => strtok($line, ' '), scannerResult($this)))->toBe(['alpha' => 'classic', 'dup' => 'classic']);
});
