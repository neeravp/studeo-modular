<?php

namespace Studeo\Modular\Tests\CheckRules;

use Illuminate\Support\ServiceProvider;

/**
 * Lets a fresh-process test point module:check at a rule-set class through the environment.
 */
final class ConfigProvider extends ServiceProvider
{
    public function boot(): void
    {
        $rules = getenv('CHECK_RULES');

        if ($rules !== false) {
            config(['modular.check.rules' => $rules]);
        }
    }
}
