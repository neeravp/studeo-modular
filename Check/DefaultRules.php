<?php

namespace Studeo\Modular\Check;

use Studeo\Check\Engine\RuleSet;
use Studeo\Check\Profiles\ActionRules;
use Studeo\Check\Profiles\Cap1Rules;
use Studeo\Check\Profiles\LaravelRules;
use Studeo\Modular\Check\Rules\ControllerShape;
use Studeo\Modular\Check\Rules\FlatModules;
use Studeo\Modular\Check\Rules\ModuleIsolation;
use Studeo\Modular\Support\Attributes\SystemAction;
use Studeo\Modular\Support\InputData;
use Studeo\Modular\Support\OutputData;

/**
 * The rules module:check runs unless config modular.check.rules names another class. An app
 * extends this class and returns its own set from rules().
 */
class DefaultRules
{
    public function rules(): RuleSet
    {
        return Cap1Rules::make()->rules()->merge(
            RuleSet::of(ModuleIsolation::options(), FlatModules::options()),
            ActionRules::make(
                systemAttribute: SystemAction::class,
                attributionHint: 'carry attribution in a #[Context] Input field',
            )->rules(),
            LaravelRules::make(inputBase: InputData::class, outputBase: OutputData::class)->rules(),
            RuleSet::of(ControllerShape::options()),
        );
    }
}
