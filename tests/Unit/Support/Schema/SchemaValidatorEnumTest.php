<?php

// Rule 7: defaults, by FieldDefaultParser
it('reports a default that does not fit, with the parser message', function () {
    $issue = schemaIssues('views:integer:default(abc)')[0];

    expect($issue->code)->toBe('field_default')
        ->and($issue->message)->toContain('[views]')->toContain('[integer]')->toContain('[abc]');
});

it('accepts a default that fits, including a structured number for a decimal', function () {
    expect(issueCodes('views:integer:default(0),price:decimal(8,2):default(1.50),note:string:nullable:default(null)'))->toBe([])
        ->and(issueCodes(['price' => ['type' => 'decimal(8,2)', 'default' => 10.5]]))->toBe([])
        ->and(issueCodes(['price' => ['type' => 'decimal(8,2)', 'default' => 10.505]]))->toBe(['error:field_default']);
});

// Rule 8: enum references, by EnumFieldReference
it('reports a legacy case list and a missing enum class', function () {
    expect(issueCodes('status:enum(draft,published)'))->toBe(['error:field_enum'])
        ->and(issueCodes('status:enum'))->toBe(['error:field_enum'])
        ->and(issueCodes(['status' => ['type' => 'enum', 'cases' => ['a']]]))->toBe(['error:field_enum'])
        ->and(issueCodes(['status' => ['type' => 'enum']]))->toBe(['error:field_enum'])
        ->and(schemaIssues('status:enum(draft,published)')[0]->message)->toContain('module:enum PostStatus --cases=draft,published')->toContain('status:enum(PostStatus)');
});

it('accepts an enum named by class, in both forms, without a module', function () {
    expect(issueCodes('status:enum(PostStatus)'))->toBe([])
        ->and(issueCodes(['status' => ['type' => 'enum', 'enum' => 'PostStatus']]))->toBe([])
        ->and(issueCodes('status:enum(PostStatus):default(anything)'))->toBe([]);
});

it('rejects an enum reference on a field that is not an enum', function () {
    expect(issueCodes(['x' => ['type' => 'string', 'enum' => 'PostStatus']]))->toBe(['error:field_enum']);
});

// Rules 9 and 10: the enum file, with a module
it('requires the enum file, string backing, short values and a real default when there is a module', function () {
    $module = moduleWithEnum("<?php\nenum PostStatus: string\n{\n    case Draft = 'draft';\n    case Published = 'published';\n}\n");

    expect(issueCodes('status:enum(PostStatus):default(draft)', $module))->toBe([])
        ->and(issueCodes('status:enum(PostStatus)', $module))->toBe([])
        ->and(issueCodes('status:enum(Missing)', $module))->toBe(['error:enum_missing'])
        ->and(schemaIssues('status:enum(Missing)', $module)[0]->message)->toContain('Enums/Missing.php')->toContain('module:enum Missing --cases=draft,published');

    $bad = schemaIssues('status:enum(PostStatus):default(archived)', $module)[0];

    expect($bad->code)->toBe('enum_default')->and($bad->alternatives)->toBe(['draft', 'published']);
});

it('skips the module rules when no module is given', function () {
    expect(issueCodes('status:enum(Missing):default(whatever)'))->toBe([]);
});

it('rejects an enum that is not string-backed, not an enum, or has values over 30 characters', function (string $source, string $code) {
    expect(issueCodes('status:enum(PostStatus)', moduleWithEnum($source)))->toBe([$code]);
})->with([
    'int backed' => ["<?php\nenum PostStatus: int\n{\n    case One = 1;\n}\n", 'error:enum_backing'],
    'not backed' => ["<?php\nenum PostStatus\n{\n    case One;\n}\n", 'error:enum_backing'],
    'not an enum' => ["<?php\nclass PostStatus {}\n", 'error:enum_missing'],
    'long value' => ["<?php\nenum PostStatus: string\n{\n    case Long = 'a_value_that_is_far_longer_than_thirty';\n}\n", 'error:enum_value_length'],
]);

it('warns, and does not fail, when a case value cannot be read', function () {
    $module = moduleWithEnum("<?php\nenum PostStatus: string\n{\n    case Draft = 'draft';\n    case Other = self::Draft->value . 'x';\n}\n");

    expect(issueCodes('status:enum(PostStatus)', $module))->toBe(['warning:enum_unreadable'])
        ->and(issueCodes('status:enum(PostStatus):default(draft)', $module))->toBe(['warning:enum_unreadable'])
        ->and(issueCodes('status:enum(PostStatus):default(nope)', $module))->toBe(['warning:enum_unreadable', 'warning:enum_default_unverified']);
});
