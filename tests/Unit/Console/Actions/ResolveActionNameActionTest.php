<?php

use Studeo\Modular\Console\Actions\ResolveActionNameAction;

it('normalises the name and appends Action only when missing', function (string $given, string $expected) {
    expect(app(ResolveActionNameAction::class)->execute($given))->toBe($expected);
})->with([
    ['CreatePost', 'CreatePostAction'],
    ['CreatePostAction', 'CreatePostAction'],
    ['create post', 'CreatePostAction'],
    ['Posts\\CreatePost', 'Posts/CreatePostAction'],
    ['Reaction', 'ReactionAction'],
]);

it('rejects a name that is only Action', function (string $given) {
    app(ResolveActionNameAction::class)->execute($given);
})->with(['Action', 'action', 'Posts/Action'])->throws(RuntimeException::class, 'only Action');

it('returns the Input path that pairs with an action name', function (string $action, string $input) {
    expect(app(ResolveActionNameAction::class)->pairedInput($action))->toBe($input);
})->with([
    ['CreatePostAction', 'CreatePostInput'],
    ['Posts/CreatePostAction', 'Posts/CreatePostInput'],
]);

it('derives the ability from the action name without its suffix', function (string $action, string $ability) {
    expect(app(ResolveActionNameAction::class)->ability($action))->toBe($ability);
})->with([
    ['CreatePostAction', 'create-post'],
    ['Posts/PublishScheduledPostAction', 'publish-scheduled-post'],
]);
