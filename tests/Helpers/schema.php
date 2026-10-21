<?php

use Studeo\Modular\Console\Actions\ParseCliSchemaAction;
use Studeo\Modular\Console\Actions\ParseStructuredSchemaAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\SchemaIssue;
use Studeo\Modular\Support\Schema\SchemaValidator;

/**
 * @param  string|array<string, mixed>  $schema  a CLI string, or the decoded structured form
 * @return array<int, SchemaIssue>
 */
function schemaIssues(string|array $schema, ?ModuleDefinition $module = null): array
{
    $raw = is_array($schema) ? (new ParseStructuredSchemaAction)->read($schema) : (new ParseCliSchemaAction)->read($schema);

    return (new SchemaValidator)->validate($raw, $module);
}

/**
 * @return array<int, string>
 */
function issueCodes(string|array $schema, ?ModuleDefinition $module = null): array
{
    return array_map(fn (SchemaIssue $issue): string => $issue->severity.':'.$issue->code, schemaIssues($schema, $module));
}

function moduleWithEnum(string $enumSource): ModuleDefinition
{
    $path = sys_get_temp_dir().'/schema-module-'.bin2hex(random_bytes(4));
    mkdir($path.'/Enums', 0777, true);
    mkdir($path.'/Models', 0777, true);
    file_put_contents($path.'/Enums/PostStatus.php', $enumSource);

    register_shutdown_function(function () use ($path): void {
        foreach (glob("{$path}/*/*") ?: [] as $file) {
            unlink($file);
        }
        array_map('rmdir', glob("{$path}/*") ?: []);
        rmdir($path);
    });

    return new ModuleDefinition('Blog', 'Studeo\\Blog', $path);
}
