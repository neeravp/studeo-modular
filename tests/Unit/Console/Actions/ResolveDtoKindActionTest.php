<?php

use Studeo\Modular\Console\Actions\ResolveDtoKindAction;
use Studeo\Modular\DataObjects\DtoKind;

it('maps the suffix of the normalised name to the kind and its stub', function (string $name, DtoKind $kind, string $stub) {
    $resolved = app(ResolveDtoKindAction::class)->execute($name);

    expect($resolved)->toBe($kind)->and($resolved->stub())->toBe($stub);
})->with([
    ['CreatePostInput', DtoKind::Input, 'dto.input.stub'],
    ['Comments/CommentInput', DtoKind::Input, 'dto.input.stub'],
    ['PostData', DtoKind::Output, 'dto.output.stub'],
    ['create post input', DtoKind::Input, 'dto.input.stub'],
]);

it('throws with the valid forms for a name with neither suffix', function (string $name) {
    app(ResolveDtoKindAction::class)->execute($name);
})->with(['Post', 'PostDto', 'Input', 'Data'])->throws(RuntimeException::class, 'must end in Input or Data');
