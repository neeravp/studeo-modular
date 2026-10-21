<?php

namespace Studeo\Modular\Support;

use Studeo\Modular\DataObjects\ViteStatus;
use Studeo\Modular\Enums\FrontendFileState;

final class FrontendInstructions
{
    private const APP_JS = <<<'TEXT'
In resources/js/app.(ts|js), update the createInertiaApp resolve callback:
   resolve: (name) => {
       if (name.includes('::')) {
           const [module, page] = name.split('::');
           return resolvePageComponent(
               `../../modules/${module}/resources/js/Pages/${page}.vue`,
               import.meta.glob('../../modules/**/resources/js/Pages/**/*.vue')
           );
       }
       return resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue'));
   },
TEXT;

    private const VITE_CONFIG = <<<'TEXT'
In vite.config.(ts|js), configure unplugin-vue-components and module refresh:
   Components({ dirs: ['resources/js/Components', 'modules/*/resources/js/Components'], dts: true }),
   laravel({ input: 'resources/js/app.js', refresh: ['resources/**', 'modules/**/resources/**'] }),
TEXT;

    /**
     * What to change by hand in the files that are already customised, or an empty string.
     */
    public static function for(ViteStatus $status): string
    {
        $steps = array_filter([
            $status->appJs === FrontendFileState::Custom ? self::APP_JS : null,
            $status->viteConfig === FrontendFileState::Custom ? self::VITE_CONFIG : null,
        ]);

        if ($steps === []) {
            return '';
        }

        $numbered = array_map(fn (int $index, string $step): string => ($index + 1).'. '.$step, array_keys($steps), $steps);

        return "Existing frontend configuration detected. Nothing was changed in it. To enable modular autodiscovery for your setup, verify the following:\n".implode("\n", $numbered);
    }
}
