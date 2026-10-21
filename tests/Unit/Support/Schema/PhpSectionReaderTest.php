<?php

use Studeo\Modular\Support\Schema\PhpSectionReader;

beforeEach(function () {
    $this->reader = new PhpSectionReader;
});

it('reads the fillable list', function () {
    $source = "<?php\nclass Post\n{\n    /** @var list<string> */\n    protected \$fillable = [\n        'title',\n        'slug',\n    ];\n}\n";

    expect($this->reader->fillable($source))->toBe(['title', 'slug']);
});

it('reads an empty fillable list, and ignores a comment placeholder', function () {
    expect($this->reader->fillable("<?php\nclass P { protected \$fillable = [\n //\n]; }"))->toBe([]);
});

it('cannot read a fillable that is missing or holds anything but string literals', function () {
    expect($this->reader->fillable("<?php\nclass P {}"))->toBeNull()
        ->and($this->reader->fillable("<?php\nclass P { protected \$fillable = [self::A, 'b']; }"))->toBeNull()
        ->and($this->reader->fillable("<?php\nclass P { protected \$fillable = parent::\$fillable; }"))->toBeNull();
});

it('reads the casts() map with the source text of each value', function () {
    $source = "<?php\nclass P\n{\n    protected function casts(): array\n    {\n        return [\n            'created_at' => 'immutable_datetime',\n            'price' => 'decimal:2',\n            'status' => \\Studeo\\Blog\\Enums\\PostStatus::class,\n        ];\n    }\n}\n";

    expect($this->reader->casts($source))->toBe([
        'created_at' => "'immutable_datetime'",
        'price' => "'decimal:2'",
        'status' => '\\Studeo\\Blog\\Enums\\PostStatus::class',
    ]);
});

it('ignores other code around casts() and cannot read it when it is missing or computed', function () {
    $with = "<?php\nclass P\n{\n    public function other(): int { return 1; }\n    protected function casts(): array { return ['a' => 'int']; }\n    public function later(): array { return ['z' => 'x']; }\n}\n";

    expect($this->reader->casts($with))->toBe(['a' => "'int'"])
        ->and($this->reader->casts("<?php\nclass P {}"))->toBeNull()
        ->and($this->reader->casts("<?php\nclass P { protected function casts(): array { return array_merge(['a' => 'int'], []); } }"))->toBeNull();
});

it('reads the keys of the factory definition, whatever the values are', function () {
    $source = "<?php\nclass F\n{\n    public function definition(): array\n    {\n        return [\n            'title' => fake()->sentence(),\n            'tags' => [1, 2, ['a' => 'b']],\n            'price' => number_format(fake()->randomFloat(2, 0, 9999), 2, '.', ''),\n        ];\n    }\n}\n";

    expect($this->reader->factoryKeys($source))->toBe(['title', 'tags', 'price'])
        ->and($this->reader->factoryKeys("<?php\nclass F {}"))->toBeNull();
});

it('reads the migration columns as normalised statements, skipping the id and timestamps', function () {
    $source = <<<'PHP'
        <?php
        return new class extends Migration {
            public function up(): void
            {
                Schema::create('posts', function (Blueprint $table) {
                    $table->id();
                    $table->string('title');
                    $table->decimal('price', 8, 2)
                        ->nullable()
                        ->default('1.00');
                    $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
                    $table->timestamps();
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('posts');
            }
        };
        PHP;

    expect($this->reader->migrationColumns($source))->toBe([
        'title' => "\$table->string('title');",
        'price' => "\$table->decimal('price', 8, 2)->nullable()->default('1.00');",
        'author_id' => "\$table->foreignId('author_id')->constrained('users')->cascadeOnDelete();",
    ])->and($this->reader->migrationColumns('<?php return new class {};'))->toBeNull();
});
