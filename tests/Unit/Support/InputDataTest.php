<?php

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Studeo\Modular\Support\Attributes\Collection as CollectionOf;
use Studeo\Modular\Support\Attributes\Context;
use Studeo\Modular\Support\InputData;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

final readonly class TestValidationUserInput extends InputData
{
    protected function __construct(
        public string $name,
        public string $email,
        #[Context]
        public ?int $userId = null,
    ) {}

    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3'],
            'email' => ['required', 'email'],
            'user_id' => ['nullable', 'integer'],
        ];
    }

    public static function messages(): array
    {
        return [
            'name.min' => 'The provided name is too short.',
        ];
    }

    public static function attributes(): array
    {
        return [
            'email' => 'work email',
        ];
    }
}

final readonly class TestCoercionInput extends InputData
{
    protected function __construct(
        public int $count,
        public bool $active,
    ) {}

    public static function rules(): array
    {
        return [
            'count' => ['required', 'integer'],
            'active' => ['required', 'boolean'],
        ];
    }
}

final readonly class TestRulelessInput extends InputData
{
    protected function __construct(
        public ?string $note = 'default',
    ) {}

    public static function rules(): array
    {
        return [];
    }
}

final readonly class TestItemInput extends InputData
{
    protected function __construct(
        public string $name,
        public int $qty,
    ) {}

    public static function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            'qty' => ['required', 'integer'],
        ];
    }
}

final readonly class TestOrderInput extends InputData
{
    /**
     * @param  array<int, TestItemInput>  $items
     */
    protected function __construct(
        public string $number,
        #[CollectionOf(TestItemInput::class)]
        public array $items,
    ) {}

    public static function rules(): array
    {
        return [
            'number' => ['required', 'string'],
            'items' => ['required', 'array', 'min:1'],
            ...self::nestedRules('items.*', TestItemInput::class),
        ];
    }
}

test('it validates and creates a DTO from an array successfully', function () {
    $dto = TestValidationUserInput::fromValidated([
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);

    expect($dto->name)->toBe('John Doe')
        ->and($dto->email)->toBe('john@example.com')
        ->and($dto->userId)->toBeNull();
});

test('it throws ValidationException on invalid data', function () {
    TestValidationUserInput::fromValidated([
        'name' => 'John Doe',
        'email' => 'not-an-email',
    ]);
})->throws(ValidationException::class);

test('it uses custom validation messages and attributes', function () {
    try {
        TestValidationUserInput::fromValidated(['name' => 'Jo', 'email' => 'invalid']);
        $this->fail('ValidationException was not thrown');
    } catch (ValidationException $e) {
        $errors = $e->errors();
        expect($errors['name'][0])->toBe('The provided name is too short.')
            ->and($errors['email'][0])->toContain('work email');
    }
});

test('it validates and creates a DTO from an HTTP Request', function () {
    $request = Request::create('/users', 'POST', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]);

    $dto = TestValidationUserInput::fromRequest($request);

    expect($dto->name)->toBe('Jane Doe')
        ->and($dto->email)->toBe('jane@example.com');
});

test('it takes #[Context] values from the context', function () {
    $request = Request::create('/users', 'POST', ['name' => 'Jane Doe', 'email' => 'jane@example.com']);

    $dto = TestValidationUserInput::fromRequest($request, ['user_id' => 101]);

    expect($dto->userId)->toBe(101);
});

test('it excludes a #[Context] value from the request payload', function () {
    $dto = TestValidationUserInput::fromValidated(
        ['name' => 'Jane Doe', 'email' => 'jane@example.com', 'user_id' => 999],
        ['user_id' => 101],
    );
    $withoutContext = TestValidationUserInput::fromValidated(
        ['name' => 'Jane Doe', 'email' => 'jane@example.com', 'user_id' => 999],
    );

    expect($dto->userId)->toBe(101)
        ->and($withoutContext->userId)->toBeNull();
});

test('it throws on a context key that is not a #[Context] property', function () {
    TestValidationUserInput::fromValidated(
        ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
        ['name' => 'Hijacked'],
    );
})->throws(InvalidArgumentException::class, 'Unknown context [name]');

test('it coerces request strings into the declared scalar types', function () {
    $dto = TestCoercionInput::fromValidated(['count' => '5', 'active' => '0']);

    expect($dto->count)->toBe(5)
        ->and($dto->active)->toBeFalse();
});

test('it reads only keys covered by rules()', function () {
    expect(TestRulelessInput::fromValidated(['note' => 'ignored'])->note)->toBe('default');
});

test('it has no public from()', function () {
    expect(method_exists(InputData::class, 'from'))->toBeFalse()
        ->and((new ReflectionClass(TestValidationUserInput::class))->getConstructor()->isProtected())->toBeTrue();
});

test('it fails at the parent when a nested item is invalid', function () {
    try {
        TestOrderInput::fromValidated([
            'number' => 'A-1',
            'items' => [['name' => 'Widget', 'qty' => 'many']],
        ]);
        $this->fail('ValidationException was not thrown');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('items.0.qty');
    }
});

test('it hydrates nested items with only the validated sub-keys', function () {
    $order = TestOrderInput::fromValidated([
        'number' => 'A-1',
        'unvalidated' => 'dropped',
        'items' => [['name' => 'Widget', 'qty' => '2', 'evil' => 'dropped']],
    ]);

    expect($order->items)->toHaveCount(1)
        ->and($order->items[0])->toBeInstanceOf(TestItemInput::class)
        ->and($order->toArray())->toBe([
            'number' => 'A-1',
            'items' => [['name' => 'Widget', 'qty' => 2]],
        ]);
});
