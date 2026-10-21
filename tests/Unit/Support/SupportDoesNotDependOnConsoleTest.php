<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

beforeEach(function () {
    $needles = ['Studeo\Modular\Console', 'Studeo\\\\Modular\\\\Console'];
    $mentionsConsole = fn (string $line): bool => collect($needles)->contains(fn (string $needle): bool => str_contains($line, $needle));

    $this->scan = fn (string $directory): array => collect(Finder::create()->files()->ignoreDotFiles(false)->in($directory)->sortByName())
        ->flatMap(fn (SplFileInfo $file): array => collect(preg_split('/\R/', $file->getContents()))
            ->filter($mentionsConsole)
            ->map(fn (string $line, int $index): string => $file->getRelativePathname().':'.($index + 1).': '.trim($line))
            ->values()
            ->all())
        ->values()
        ->all();

    $this->directory = sys_get_temp_dir().'/support-scan-'.bin2hex(random_bytes(6));
    mkdir($this->directory.'/Sub', 0777, true);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->directory);
});

it('keeps every file under Support free of the Console namespace', function () {
    $offences = ($this->scan)(dirname(__DIR__, 3).'/Support');

    expect($offences)->toBe([], "Support must not depend on Console:\n".implode("\n", $offences));
});

it('names the file and line of a use line and of a string with doubled backslashes', function () {
    file_put_contents($this->directory.'/Sub/Bad.php', "<?php\n\nuse Studeo\\Modular\\Console\\Actions\\ParseCliSchemaAction;\n");
    file_put_contents($this->directory.'/Strings.txt', "first\n'Studeo\\\\Modular\\\\Console\\\\Actions\\\\X'\n");

    expect(($this->scan)($this->directory))->toBe([
        "Strings.txt:2: 'Studeo\\\\Modular\\\\Console\\\\Actions\\\\X'",
        'Sub/Bad.php:3: use Studeo\Modular\Console\Actions\ParseCliSchemaAction;',
    ]);
});

it('passes a directory that never mentions the Console namespace', function () {
    file_put_contents($this->directory.'/Sub/Clean.php', "<?php\n\nuse Illuminate\\Console\\Command;\nuse Studeo\\Modular\\Support\\ModuleNamespace;\n");

    expect(($this->scan)($this->directory))->toBe([]);
});
