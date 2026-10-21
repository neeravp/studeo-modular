<?php

use Studeo\Modular\Console\Actions\BuildSchemaDtoAction;
use Studeo\Modular\DataObjects\DtoKind;
use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;

beforeEach(function () {
    $this->path = sys_get_temp_dir().'/dto-build-'.bin2hex(random_bytes(4));
    mkdir($this->path.'/Enums', 0777, true);
    $this->module = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->path);
    $this->stub = file_get_contents(dirname(__DIR__, 4).'/Console/stubs/dto.input.stub');
    $this->schema = fn (): SchemaDefinition => new SchemaDefinition(fields: [
        'status' => new FieldDefinition('status', 'enum', ['default(draft)'], 'Studeo\\Blog\\Enums\\PostStatus'),
    ]);
});

afterEach(function () {
    array_map('unlink', glob("{$this->path}/Enums/*") ?: []);
    rmdir($this->path.'/Enums');
    rmdir($this->path);
});

it('fails, naming the file, when the enum file is missing', function () {
    (new BuildSchemaDtoAction)->execute($this->stub, $this->module, ($this->schema)(), DtoKind::Input);
})->throws(RuntimeException::class, 'Enums/PostStatus.php');

it('fails when the enum has no case for the default', function () {
    file_put_contents($this->path.'/Enums/PostStatus.php', "<?php\n\nnamespace Studeo\\Blog\\Enums;\n\nenum PostStatus: string\n{\n    case Live = 'live';\n}\n");

    (new BuildSchemaDtoAction)->execute($this->stub, $this->module, ($this->schema)(), DtoKind::Input);
})->throws(RuntimeException::class, 'nothing was written');

it('renders the case name read from the enum file', function () {
    file_put_contents($this->path.'/Enums/PostStatus.php', "<?php\n\nnamespace Studeo\\Blog\\Enums;\n\nenum PostStatus: string\n{\n    case Live = 'live';\n    case Draft = 'draft';\n}\n");

    $content = (new BuildSchemaDtoAction)->execute($this->stub, $this->module, ($this->schema)(), DtoKind::Input);

    expect($content)->toContain('public PostStatus $status = PostStatus::Draft,')->toContain('use Studeo\\Blog\\Enums\\PostStatus;');
});
