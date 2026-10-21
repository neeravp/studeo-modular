<?php

use Studeo\Modular\Support\Schema\PhpSectionReader;

function constructorSource(string $parameters): string
{
    return "<?php\n\nnamespace A;\n\nuse X\\Context;\n\nfinal readonly class C\n{\n    protected function __construct(\n{$parameters}\n    ) {}\n}\n";
}

it('reads the constructor parameters as name => type', function () {
    $source = constructorSource("        public string \$slug,\n        #[Context]\n        public int \$authorId,\n        public ?CarbonImmutable \$at = null,\n        public Status \$status = Status::Draft,\n        public array \$tags = [1, 2],");

    expect((new PhpSectionReader)->constructorParameters($source))->toBe([
        'slug' => 'string', 'authorId' => 'int', 'at' => '?CarbonImmutable', 'status' => 'Status', 'tags' => 'array',
    ]);
});

it('keeps a fully qualified type as written and a union as one type', function () {
    $source = constructorSource("        public \\Carbon\\CarbonImmutable \$at,\n        public int|string \$either,");

    expect((new PhpSectionReader)->constructorParameters($source))->toBe(['at' => '\\Carbon\\CarbonImmutable', 'either' => 'int|string']);
});

it('reads an empty constructor as no parameters', function () {
    expect((new PhpSectionReader)->constructorParameters(constructorSource('')))->toBe([]);
});

it('returns null when there is no constructor', function () {
    expect((new PhpSectionReader)->constructorParameters("<?php\n\nfinal readonly class C {}\n"))->toBeNull();
});

it('reads only the first constructor, so one in an anonymous class later is ignored', function () {
    $source = constructorSource('        public string $slug,')."\n".'$x = new class { public function __construct(public int $other) {} };';

    expect((new PhpSectionReader)->constructorParameters($source))->toBe(['slug' => 'string']);
});
