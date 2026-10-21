<?php

use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\Support\RuleExpression;
use Studeo\Modular\Support\ValidationRuleResolver;

it('resolves presence rules based on nullable modifier', function (): void {
    $required = new FieldDefinition(name: 'title', type: 'string');
    $nullable = new FieldDefinition(name: 'bio', type: 'text', modifiers: ['nullable']);

    expect(ValidationRuleResolver::resolve($required))->toContain('required')
        ->and(ValidationRuleResolver::resolve($nullable))->toContain('nullable');
});

it('resolves string rules with default and parameterized max constraints', function (): void {
    $standardString = new FieldDefinition(name: 'title', type: 'string');
    $parameterizedString = new FieldDefinition(name: 'code', type: 'string', typeArguments: ['10']);
    $char = new FieldDefinition(name: 'iso', type: 'char', typeArguments: ['3']);

    expect(ValidationRuleResolver::resolve($standardString))->toBe(['required', 'string', 'max:255'])
        ->and(ValidationRuleResolver::resolve($parameterizedString))->toBe(['required', 'string', 'max:10'])
        ->and(ValidationRuleResolver::resolve($char))->toBe(['required', 'string', 'max:3']);
});

it('resolves specific string formats for emails, passwords, and urls', function (): void {
    $email = new FieldDefinition(name: 'email', type: 'string');
    $contactEmail = new FieldDefinition(name: 'contact_email', type: 'string');
    $password = new FieldDefinition(name: 'password', type: 'string');
    $website = new FieldDefinition(name: 'website_url', type: 'string');

    expect(ValidationRuleResolver::resolve($email))->toContain('email')
        ->and(ValidationRuleResolver::resolve($contactEmail))->toContain('email')
        ->and(ValidationRuleResolver::resolve($password))->toContain('min:8')
        ->and(ValidationRuleResolver::resolve($website))->toContain('url');
});

it('resolves numeric, boolean, date, and array types', function (): void {
    $integer = new FieldDefinition(name: 'votes', type: 'integer');
    $decimal = new FieldDefinition(name: 'price', type: 'decimal', typeArguments: ['8', '2']);
    $boolean = new FieldDefinition(name: 'is_active', type: 'boolean');
    $date = new FieldDefinition(name: 'published_at', type: 'datetime');
    $json = new FieldDefinition(name: 'meta', type: 'json');
    $uuid = new FieldDefinition(name: 'guid', type: 'uuid');

    expect(ValidationRuleResolver::resolve($integer))->toBe(['required', 'integer'])
        ->and(ValidationRuleResolver::resolve($decimal))->toBe(['required', 'numeric'])
        ->and(ValidationRuleResolver::resolve($boolean))->toBe(['required', 'boolean'])
        ->and(ValidationRuleResolver::resolve($date))->toBe(['required', 'date'])
        ->and(ValidationRuleResolver::resolve($json))->toBe(['required', 'array'])
        ->and(ValidationRuleResolver::resolve($uuid))->toBe(['required', 'string', 'uuid']);
});

it('resolves enum rules as Rule::enum expressions', function (): void {
    $rules = ValidationRuleResolver::resolve(new FieldDefinition(name: 'status', type: 'enum', enum: 'Studeo\\Blog\\Enums\\PostStatus'));

    expect($rules[0])->toBe('required')
        ->and($rules[1])->toBeInstanceOf(RuleExpression::class)
        ->and($rules[1]->code)->toBe('\\Illuminate\\Validation\\Rule::enum(\\Studeo\\Blog\\Enums\\PostStatus::class)');
});

it('never resolves a unique rule, whatever the modifier', function (): void {
    $unique = new FieldDefinition(name: 'slug', type: 'string', modifiers: ['unique']);

    expect(ValidationRuleResolver::resolve($unique))->toBe(['required', 'string', 'max:255']);
});

it('never resolves an exists rule for foreign keys or _id names', function (): void {
    $standardForeign = new FieldDefinition(name: 'user_id', type: 'foreignId');
    $customConstrained = new FieldDefinition(
        name: 'author_id',
        type: 'foreignId',
        modifiers: ["constrained('users')"],
    );

    $suffixOnly = new FieldDefinition(name: 'legacy_id', type: 'integer');

    expect(ValidationRuleResolver::resolve($standardForeign))->toBe(['required', 'integer'])
        ->and(ValidationRuleResolver::resolve($customConstrained))->toBe(['required', 'integer'])
        ->and(ValidationRuleResolver::resolve($suffixOnly))->toBe(['required', 'integer']);
});
