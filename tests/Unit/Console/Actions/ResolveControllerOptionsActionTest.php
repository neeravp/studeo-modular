<?php

use Studeo\Modular\Console\Actions\ResolveControllerOptionsAction;
use Studeo\Modular\DataObjects\ControllerGenerationOptions;
use Studeo\Modular\Enums\ControllerType;

function controllerOptions(array $flags): ControllerGenerationOptions
{
    return (new ResolveControllerOptionsAction)->execute($flags);
}

it('makes an invokable controller by default', function () {
    $options = controllerOptions([]);

    expect($options->type)->toBe(ControllerType::Invokable)->and($options->inertia)->toBeFalse()->and($options->model)->toBeNull();
});

it('accepts each valid combination', function (array $flags, ControllerType $type, bool $inertia, ?string $model) {
    $options = controllerOptions($flags);

    expect($options->type)->toBe($type)->and($options->inertia)->toBe($inertia)->and($options->model)->toBe($model);
})->with([
    'resource' => [['resource' => true], ControllerType::Resource, false, null],
    'api' => [['api' => true], ControllerType::Api, false, null],
    'standard' => [['standard' => true], ControllerType::Standard, false, null],
    'inertia' => [['inertia' => true], ControllerType::Invokable, true, null],
    'resource and inertia' => [['resource' => true, 'inertia' => true], ControllerType::Resource, true, null],
    'resource and model' => [['resource' => true, 'model' => 'Post'], ControllerType::Resource, false, 'Post'],
    'api and model' => [['api' => true, 'model' => 'Post'], ControllerType::Api, false, 'Post'],
    'resource, inertia and model' => [['resource' => true, 'inertia' => true, 'model' => 'Post'], ControllerType::Resource, true, 'Post'],
]);

it('refuses a combination, naming the conflict', function (array $flags, string $message) {
    expect(fn () => controllerOptions($flags))->toThrow(RuntimeException::class, $message);
})->with([
    'resource and api' => [['resource' => true, 'api' => true], 'The options --resource and --api cannot be combined: choose one controller kind.'],
    'resource and standard' => [['resource' => true, 'standard' => true], 'The options --resource and --standard cannot be combined: choose one controller kind.'],
    'api and standard' => [['api' => true, 'standard' => true], 'The options --api and --standard cannot be combined: choose one controller kind.'],
    'all three' => [['resource' => true, 'api' => true, 'standard' => true], 'The options --resource, --api and --standard cannot be combined: choose one controller kind.'],
    'inertia with api' => [['inertia' => true, 'api' => true], 'The option --inertia cannot be combined with --api: it applies to the default invokable controller and to --resource.'],
    'inertia with standard' => [['inertia' => true, 'standard' => true], 'The option --inertia cannot be combined with --standard: it applies to the default invokable controller and to --resource.'],
    'model alone' => [['model' => 'Post'], 'The option --model needs --resource or --api: an invokable controller has no method that binds a model.'],
    'model with standard' => [['model' => 'Post', 'standard' => true], 'The option --model cannot be combined with --standard: it binds a model in the methods of --resource or --api.'],
    'model with inertia alone' => [['model' => 'Post', 'inertia' => true], 'The option --model needs --resource or --api: an invokable controller has no method that binds a model.'],
]);

it('carries --action, --schema, --context and --url on the default invokable controller', function () {
    $options = controllerOptions(['action' => 'CreatePost', 'schema' => '@posts', 'context' => ['author_id=actor'], 'url' => '/posts', 'model' => null, 'inertia' => false]);

    expect($options->type)->toBe(ControllerType::Invokable)
        ->and($options->action)->toBe('CreatePost')
        ->and($options->schema)->toBe('@posts')
        ->and($options->context)->toBe(['author_id=actor'])
        ->and($options->url)->toBe('/posts');
});

it('reads the empty values the command passes for unused Action options as absent', function () {
    $options = controllerOptions(['action' => null, 'schema' => null, 'context' => [], 'url' => null, 'resource' => true]);

    expect($options->action)->toBeNull()->and($options->schema)->toBeNull()->and($options->context)->toBe([])->and($options->url)->toBeNull();
});

it('refuses the Action options outside the default invokable controller, naming the conflict', function (array $flags, string $message) {
    expect(fn () => controllerOptions($flags))->toThrow(RuntimeException::class, $message);
})->with([
    'action with resource' => [['action' => 'CreatePost', 'resource' => true], 'The option --action cannot be combined with --resource: it applies to the default invokable controller only.'],
    'action with api' => [['action' => 'CreatePost', 'api' => true], 'The option --action cannot be combined with --api: it applies to the default invokable controller only.'],
    'action with standard' => [['action' => 'CreatePost', 'standard' => true], 'The option --action cannot be combined with --standard: it applies to the default invokable controller only.'],
    'schema with resource' => [['action' => 'CreatePost', 'schema' => '@posts', 'resource' => true], 'The option --action cannot be combined with --resource: it applies to the default invokable controller only.'],
    'action with inertia' => [['action' => 'CreatePost', 'inertia' => true], 'The option --action cannot be combined with --inertia: an Action controller returns back(), and Inertia page props built from DTOs are not generated yet.'],
    'schema without action' => [['schema' => '@posts'], 'The option --schema needs --action: it describes the Action the controller calls, e.g. --action=CreatePost.'],
    'context without action' => [['context' => ['author_id=actor']], 'The option --context needs --action: it supplies a context field of the Action\'s Input, e.g. --action=CreatePost.'],
    'url without action' => [['url' => '/posts'], 'The option --url needs --action: it prints the route to the Action controller, e.g. --action=CreatePost.'],
    'schema without action on resource' => [['schema' => '@posts', 'resource' => true], 'The option --schema needs --action: it describes the Action the controller calls, e.g. --action=CreatePost.'],
]);
