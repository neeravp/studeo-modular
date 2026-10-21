<?php

use Studeo\Modular\Support\Schema\ConstructorReader;

it('reads the parameters and the context parameters of the first constructor', function () {
    $source = <<<'PHP'
        <?php

        final readonly class CreatePostInput
        {
            public function __construct(
                public string $title,
                #[Context] public int $authorId,
                #[\Studeo\Modular\Support\Context] protected ?string $ip = null,
            ) {}
        }
        PHP;

    expect((new ConstructorReader)->parameters($source))->toBe(['title' => 'string', 'authorId' => 'int', 'ip' => '?string'])
        ->and((new ConstructorReader)->contextParameters($source))->toBe(['authorId', 'ip']);
});

it('returns null for both readers when the file has no constructor', function () {
    $source = "<?php\n\nfinal class C {}\n";

    expect((new ConstructorReader)->parameters($source))->toBeNull()
        ->and((new ConstructorReader)->contextParameters($source))->toBeNull();
});
