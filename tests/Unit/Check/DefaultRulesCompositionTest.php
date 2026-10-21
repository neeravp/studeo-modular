<?php

use Studeo\Check\Engine\Violation;
use Studeo\Modular\Check\DefaultRules;

/**
 * @param  array<string, string>  $files
 * @return list<Violation>
 */
function defaultRulesActionViolations(array $files): array
{
    $source = checkSource($files);
    $all = array_merge([], ...array_map(
        fn ($rule): array => $rule->violations($source),
        (new DefaultRules)->rules()->rules()
    ));

    return array_values(array_filter(
        $all,
        fn (Violation $violation): bool => in_array($violation->rule, ['system-action-usage', 'action-authorization'], true)
    ));
}

it('holds the fifteen rules in the composition order', function () {
    $expected = [
        'method-complexity',
        'file-length',
        'method-naming',
        'step-count',
        'nesting-depth',
        'condition-extraction',
        'module-isolation',
        'flat-modules',
        'action-shape',
        'system-action-usage',
        'action-authorization',
        'dto-shape',
        'migration',
        'no-dto-in-livewire-state',
        'controller-shape',
    ];

    expect(array_map(fn ($rule): string => $rule->name(), (new DefaultRules)->rules()->rules()))
        ->toBe($expected);
});

it('passes systemAttribute to system-action-usage through default rules', function () {
    $files = [
        'Actions/SweepAction.php' => phpFile(
            "#[SystemAction]\nfinal class SweepAction\n{\n    public function __invoke(): void {}\n}",
            'Studeo\\Blog\\Actions',
            "use Studeo\\Modular\\Support\\Attributes\\SystemAction;\n"
        ),
        'Controllers/Caller.php' => phpFile(
            "final class Caller\n{\n    public function run(\\Studeo\\Blog\\Actions\\SweepAction \$action): void {}\n}",
            'Studeo\\Blog\\Controllers'
        ),
    ];

    $found = defaultRulesActionViolations($files);

    expect($found)->toHaveCount(1)
        ->and($found[0]->rule)->toBe('system-action-usage')
        ->and($found[0]->subject)->toBe('Studeo\\Blog\\Actions\\SweepAction');
});

it('passes systemAttribute to action-authorization through default rules', function () {
    $files = [
        'Actions/CreatePostAction.php' => phpFile(
            "final class CreatePostAction\n{\n    public function __invoke(): void {}\n}",
            'Studeo\\Blog\\Actions'
        ),
    ];

    $found = defaultRulesActionViolations($files);

    expect($found)->toHaveCount(1)
        ->and($found[0]->rule)->toBe('action-authorization')
        ->and($found[0]->message)->toContain('#[SystemAction]');
});

it('passes attributionHint to action-authorization through default rules', function () {
    $files = [
        'Actions/SweepAction.php' => phpFile(
            "#[SystemAction]\nfinal class SweepAction\n{\n    public function __invoke(Authenticatable \$actor): void {}\n}",
            'Studeo\\Blog\\Actions',
            "use Illuminate\\Contracts\\Auth\\Authenticatable;\nuse Studeo\\Modular\\Support\\Attributes\\SystemAction;\n"
        ),
    ];

    $found = defaultRulesActionViolations($files);

    expect($found)->toHaveCount(1)
        ->and($found[0]->rule)->toBe('action-authorization')
        ->and($found[0]->message)->toContain('carry attribution in a #[Context] Input field');
});
