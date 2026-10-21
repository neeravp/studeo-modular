<?php

use Studeo\Modular\Console\Actions\ParseSchemaAction;

function deleteTestDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $items = scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir.'/'.$item;
        is_dir($path) ? deleteTestDir($path) : unlink($path);
    }
    rmdir($dir);
}

beforeEach(function () {
    $this->parser = new ParseSchemaAction;
    $this->tempDir = sys_get_temp_dir().'/modular_schema_test_'.uniqid();
    if (! is_dir($this->tempDir)) {
        mkdir($this->tempDir, 0777, true);
    }
});

afterEach(function () {
    deleteTestDir($this->tempDir);
});

it('parses inline json schema', function () {
    $json = json_encode([
        'name' => 'Article',
        'fields' => [
            'title' => 'string:unique',
            'view_count' => [
                'type' => 'integer',
                'default' => 0,
            ],
            'category_id' => [
                'type' => 'foreignId',
                'constrained' => true,
                'cascade_on_delete' => true,
            ],
        ],
        'relationships' => [
            'category' => [
                'type' => 'belongsTo',
                'model' => 'Category',
            ],
        ],
    ]);

    $schema = $this->parser->execute($json);

    expect($schema->name)->toBe('Article')
        ->and($schema->fields)->toHaveCount(3)
        ->and($schema->fields['title']->isUnique())->toBeTrue()
        ->and($schema->fields['view_count']->type)->toBe('integer')
        ->and($schema->fields['view_count']->default->value)->toBe(0)
        ->and($schema->fields['category_id']->migrationDefinition())->toBe("\$table->foreignId('category_id')->constrained()->cascadeOnDelete();")
        ->and($schema->relationships['category']->relatedModel)->toBe('Category');
});

it('parses json file from disk', function () {
    $filePath = $this->tempDir.'/schema.json';
    file_put_contents($filePath, json_encode([
        'fields' => [
            'headline' => 'string',
            'content' => 'text:nullable',
        ],
    ]));

    $schema = $this->parser->execute($filePath);

    expect($schema->fields)->toHaveCount(2)
        ->and($schema->fields['headline']->type)->toBe('string')
        ->and($schema->fields['content']->isNullable())->toBeTrue();
});

it('parses inline yaml schema', function () {
    $yaml = <<<'YAML'
name: Product
fields:
  name: string
  price_cents:
    type: integer
    default: 0
  status:
    type: enum
    enum: AccountStatus
    default: active
relationships:
  orders:
    type: belongsToMany
    model: Order
YAML;

    $schema = $this->parser->execute($yaml);

    expect($schema->name)->toBe('Product')
        ->and($schema->fields)->toHaveCount(3)
        ->and($schema->fields['name']->type)->toBe('string')
        ->and($schema->fields['price_cents']->type)->toBe('integer')
        ->and($schema->fields['status']->enum)->toBe('AccountStatus')
        ->and($schema->relationships['orders']->type)->toBe('belongsToMany')
        ->and($schema->relationships['orders']->relatedModel)->toBe('Order');
});

it('parses yaml file with comments from disk', function () {
    $filePath = $this->tempDir.'/product.yaml';
    $yaml = <<<'YAML'
# Domain blueprint for Invoices
name: Invoice
fields:
  invoice_number: string:unique # e.g. INV-2026-001
  total:
    type: decimal
    nullable: false
  notes: text:nullable
relationships:
  customer:
    type: belongsTo
    model: Customer
YAML;

    file_put_contents($filePath, $yaml);

    $schema = $this->parser->execute($filePath);

    expect($schema->name)->toBe('Invoice')
        ->and($schema->fields['invoice_number']->isUnique())->toBeTrue()
        ->and($schema->fields['total']->type)->toBe('decimal')
        ->and($schema->fields['notes']->isNullable())->toBeTrue()
        ->and($schema->relationships['customer']->relatedModel)->toBe('Customer');
});
