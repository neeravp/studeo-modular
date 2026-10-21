<?php

use Studeo\Modular\Console\Actions\ParseCliSchemaAction;
use Studeo\Modular\DataObjects\FactoryDefinition;
use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\Support\FakerDefinitionResolver;
use Studeo\Modular\Support\Schema\ForeignKeyFaker;
use Studeo\Modular\Support\Schema\ModuleModels;

it('resolves exact name based faker definitions', function (): void {
    $email = new FieldDefinition(name: 'email', type: 'string');
    $password = new FieldDefinition(name: 'password', type: 'string');
    $phone = new FieldDefinition(name: 'phone_number', type: 'string');
    $address = new FieldDefinition(name: 'street_address', type: 'string');

    expect(FakerDefinitionResolver::resolve($email))->toBe('fake()->unique()->safeEmail()')
        ->and(FakerDefinitionResolver::resolve($password))->toBe("bcrypt('password')")
        ->and(FakerDefinitionResolver::resolve($phone))->toBe('fake()->phoneNumber()')
        ->and(FakerDefinitionResolver::resolve($address))->toBe('fake()->streetAddress()');
});

it('resolves patterned name faker definitions for emails and urls', function (): void {
    $billingEmail = new FieldDefinition(name: 'billing_email', type: 'string');
    $profileUrl = new FieldDefinition(name: 'profile_url', type: 'string');

    expect(FakerDefinitionResolver::resolve($billingEmail))->toBe('fake()->unique()->safeEmail()')
        ->and(FakerDefinitionResolver::resolve($profileUrl))->toBe('fake()->url()');
});

function factoryDefinition(string $fields, $module, ?string $userModel = null): FactoryDefinition
{
    return FakerDefinitionResolver::definition((new ParseCliSchemaAction)->execute($fields), new ForeignKeyFaker(new ModuleModels, $module, $userModel));
}

it('fakes a foreign key to a model of the module through that model\'s factory', function (): void {
    $module = moduleWithModels(['Category', 'Article' => 'posts'], ['Category', 'Article']);

    $definition = factoryDefinition('category_id:foreignId:constrained,post_id:foreignId:constrained,tag_id:foreignUuid:constrained(categories)', $module);

    expect($definition->attributes)->toBe([
        'category_id' => '\\Studeo\\Blog\\Models\\Category::factory()',
        'post_id' => '\\Studeo\\Blog\\Models\\Article::factory()',
        'tag_id' => '\\Studeo\\Blog\\Models\\Category::factory()',
    ])->and($definition->omitted)->toBe([]);
});

it('fakes a nullable foreign key as null, whatever it points at', function (): void {
    $definition = factoryDefinition('editor_id:foreignId:nullable:constrained(users),owner_id:foreignId:nullable', moduleWithModels([]), 'App\\Models\\User');

    expect($definition->attributes)->toBe(['editor_id' => 'null', 'owner_id' => 'null'])->and($definition->omitted)->toBe([]);
});

it('fakes a foreign key to the auth user table through the configured class', function (): void {
    $definition = factoryDefinition('author_id:foreignId:constrained(customers),user_id:foreignId:constrained', moduleWithModels([]), 'Fixture\\Auth\\Customer');

    expect($definition->attributes)->toBe(['author_id' => "fn () => config('auth.providers.users.model')::factory()"])
        ->and(array_keys($definition->omitted))->toBe(['user_id']);
});

it('derives the auth user table from the class name without loading it', function (): void {
    expect(class_exists('Fixture\\Auth\\BlogPerson'))->toBeFalse()
        ->and(factoryDefinition('blog_person_id:foreignId', moduleWithModels([]), 'Fixture\\Auth\\BlogPerson')->attributes)->toHaveKey('blog_person_id');
});

it('prefers a model of the module over the auth user for the same table', function (): void {
    $module = moduleWithModels(['User'], ['User']);

    expect(factoryDefinition('user_id:foreignId:constrained', $module, 'App\\Models\\User')->attributes)->toBe(['user_id' => '\\Studeo\\Blog\\Models\\User::factory()']);
});

it('leaves out a key whose model has no factory and says which', function (): void {
    $definition = factoryDefinition('draft_id:foreignId:constrained', moduleWithModels(['Draft']));

    expect($definition->attributes)->toBe([])
        ->and($definition->omitted['draft_id'])->toContain('draft_id')->toContain('Draft')->toContain('database/factories/DraftFactory.php');
});

it('leaves out a key that points outside the module and the auth user and says which', function (): void {
    $definition = factoryDefinition('warehouse_id:foreignId,title:string', moduleWithModels(['Category'], ['Category']), 'App\\Models\\User');

    expect(array_keys($definition->attributes))->toBe(['title'])
        ->and($definition->omitted['warehouse_id'])->toContain('warehouse_id')->toContain('warehouses');
});

it('resolves enums to a random case of the enum class', function (): void {
    $status = new FieldDefinition(name: 'status', type: 'enum', enum: 'Studeo\\Blog\\Enums\\PostStatus');

    expect(FakerDefinitionResolver::resolve($status))->toBe('fake()->randomElement(\\Studeo\\Blog\\Enums\\PostStatus::cases())');
});

it('resolves standard types to type-based fakers', function (): void {
    expect(FakerDefinitionResolver::resolve(new FieldDefinition(name: 'body', type: 'text')))->toBe('fake()->paragraph()')
        ->and(FakerDefinitionResolver::resolve(new FieldDefinition(name: 'is_active', type: 'boolean')))->toBe('fake()->boolean()')
        ->and(FakerDefinitionResolver::resolve(new FieldDefinition(name: 'quantity', type: 'integer')))->toBe('fake()->randomNumber()')
        ->and(FakerDefinitionResolver::resolve(new FieldDefinition(name: 'price', type: 'decimal')))->toBe("number_format(fake()->randomFloat(2, 0, 9999), 2, '.', '')")
        ->and(FakerDefinitionResolver::resolve(new FieldDefinition(name: 'rate', type: 'decimal', typeArguments: ['8', '4'])))->toBe("number_format(fake()->randomFloat(4, 0, 9999), 4, '.', '')")
        ->and(FakerDefinitionResolver::resolve(new FieldDefinition(name: 'meta', type: 'json')))->toBe('[]')
        ->and(FakerDefinitionResolver::resolve(new FieldDefinition(name: 'guid', type: 'uuid')))->toBe('fake()->uuid()');
});
