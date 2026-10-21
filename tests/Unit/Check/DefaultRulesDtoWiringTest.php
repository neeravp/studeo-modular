<?php

use Studeo\Check\Engine\Violation;
use Studeo\Modular\Check\DefaultRules;

/**
 * @param  array<string, string>  $files
 * @return list<Violation>
 */
function defaultRulesDtoViolations(array $files): array
{
    $source = checkSource($files);
    $all = array_merge([], ...array_map(
        fn ($rule): array => $rule->violations($source),
        (new DefaultRules)->rules()->rules()
    ));

    return array_values(array_filter(
        $all,
        fn (Violation $violation): bool => in_array($violation->rule, ['dto-shape', 'no-dto-in-livewire-state'], true)
    ));
}

it('flags an output data class missing the output base configured in default rules', function () {
    $files = [
        'Data/PostData.php' => phpFile(
            'final readonly class PostData {}',
            'Studeo\\Blog\\Data'
        ),
    ];

    $found = defaultRulesDtoViolations($files);

    expect($found)->toHaveCount(1)
        ->and($found[0]->rule)->toBe('dto-shape')
        ->and($found[0]->message)->toContain('Studeo\\Modular\\Support\\OutputData');
});

it('flags an input class missing the input base configured in default rules', function () {
    $files = [
        'Data/CreatePostInput.php' => phpFile(
            "final readonly class CreatePostInput\n{\n    protected function __construct() {}\n}",
            'Studeo\\Blog\\Data'
        ),
    ];

    $found = defaultRulesDtoViolations($files);

    expect($found)->toHaveCount(1)
        ->and($found[0]->rule)->toBe('dto-shape')
        ->and($found[0]->message)->toContain('Studeo\\Modular\\Support\\InputData');
});

it('flags livewire public state holding an input class located outside data', function () {
    $files = [
        'Support/WeirdInput.php' => phpFile(
            "use Studeo\\Modular\\Support\\InputData;\n\nfinal readonly class WeirdInput extends InputData {}",
            'Studeo\\Blog\\Support'
        ),
        'Livewire/PostEditor.php' => phpFile(
            "use Livewire\\Component;\nuse Studeo\\Blog\\Support\\WeirdInput;\n\nclass PostEditor extends Component\n{\n    public WeirdInput \$input;\n}",
            'Studeo\\Blog\\Livewire'
        ),
    ];

    $found = defaultRulesDtoViolations($files);

    expect($found)->toHaveCount(1)
        ->and($found[0]->rule)->toBe('no-dto-in-livewire-state')
        ->and($found[0]->subject)->toBe('PostEditor::$input');
});

it('flags livewire public state holding an output data class located outside data', function () {
    $files = [
        'Support/WeirdData.php' => phpFile(
            "use Studeo\\Modular\\Support\\OutputData;\n\nfinal readonly class WeirdData extends OutputData {}",
            'Studeo\\Blog\\Support'
        ),
        'Livewire/PostEditor.php' => phpFile(
            "use Livewire\\Component;\nuse Studeo\\Blog\\Support\\WeirdData;\n\nclass PostEditor extends Component\n{\n    public WeirdData \$data;\n}",
            'Studeo\\Blog\\Livewire'
        ),
    ];

    $found = defaultRulesDtoViolations($files);

    expect($found)->toHaveCount(1)
        ->and($found[0]->rule)->toBe('no-dto-in-livewire-state')
        ->and($found[0]->subject)->toBe('PostEditor::$data');
});
