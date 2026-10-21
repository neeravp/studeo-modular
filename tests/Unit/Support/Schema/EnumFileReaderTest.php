<?php

use Studeo\Modular\Support\Schema\EnumFileReader;

beforeEach(function () {
    $this->file = tempnam(sys_get_temp_dir(), 'enum');
    $this->reader = new EnumFileReader;
});

afterEach(function () {
    @unlink($this->file);
});

it('reads the backing type and literal case values without loading the class', function () {
    file_put_contents($this->file, "<?php\nnamespace A;\nenum PostStatus: string\n{\n    case Draft = 'draft';\n    case Published = \"published\";\n}\n");

    expect($this->reader->read($this->file))->toBe([
        'declared' => true,
        'backing' => 'string',
        'cases' => ['Draft' => 'draft', 'Published' => 'published'],
    ])->and(enum_exists('A\\PostStatus', false))->toBeFalse();
});

it('reports a case whose value is not a plain literal as unreadable', function () {
    file_put_contents($this->file, "<?php\nenum S: string\n{\n    case A = 'a';\n    case B = self::A->value . 'x';\n    case C = Other::X;\n}\n");

    expect($this->reader->read($this->file)['cases'])->toBe(['A' => 'a', 'B' => null, 'C' => null]);
});

it('does not mistake a switch or match in a method for a case', function () {
    file_put_contents($this->file, "<?php\nenum S: string\n{\n    case A = 'a';\n    public function f(int \$n): int { switch (\$n) { case 1: return 2; } return match(\$n) { default => 0 }; }\n}\n");

    expect($this->reader->read($this->file)['cases'])->toBe(['A' => 'a']);
});

it('reads an integer-backed enum and a pure enum', function () {
    file_put_contents($this->file, "<?php\nenum N: int\n{\n    case One = 1;\n}\n");
    expect($this->reader->read($this->file))->toMatchArray(['backing' => 'int', 'cases' => ['One' => '1']]);

    file_put_contents($this->file, '<?php enum Plain { case A; case B; }');
    expect($this->reader->read($this->file))->toMatchArray(['backing' => null, 'cases' => ['A' => null, 'B' => null]]);
});

it('says when a file declares no enum, or does not exist', function () {
    file_put_contents($this->file, '<?php class Foo {}');

    expect($this->reader->read($this->file))->toMatchArray(['declared' => false])
        ->and($this->reader->read($this->file.'-missing'))->toBeNull();
});
