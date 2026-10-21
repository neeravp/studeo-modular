<?php

namespace Studeo\Modular\Console\Actions;

use RuntimeException;
use Studeo\Modular\DataObjects\ControllerGenerationOptions;
use Studeo\Modular\Enums\ControllerType;

/**
 * Turns the flags of module:controller into its options, or refuses them. A controller is invokable
 * unless --resource, --api or --standard says otherwise, and those three exclude one another.
 * --action (with --schema, --context and --url) applies to the default invokable controller only.
 */
final class ResolveControllerOptionsAction
{
    private const array KINDS = ['resource' => ControllerType::Resource, 'api' => ControllerType::Api, 'standard' => ControllerType::Standard];

    private const array NEEDS_ACTION = [
        'schema' => 'it describes the Action the controller calls',
        'context' => 'it supplies a context field of the Action\'s Input',
        'url' => 'it prints the route to the Action controller',
    ];

    /**
     * @param  array<string, mixed>  $flags
     *
     * @throws RuntimeException
     */
    public function execute(array $flags): ControllerGenerationOptions
    {
        $kinds = array_keys(array_filter(array_intersect_key($flags, self::KINDS)));
        $type = $this->kind($kinds);
        $inertia = (bool) ($flags['inertia'] ?? false);
        $model = ($flags['model'] ?? null) === null ? null : (string) $flags['model'];

        $this->checkInertia($kinds, $inertia);
        $this->checkModel($type, $model);
        $this->checkAction($flags, $kinds, $inertia);

        return new ControllerGenerationOptions($type, $model, $inertia, ...$this->actionOptions($flags));
    }

    /**
     * @param  array<string, mixed>  $flags
     * @return array{action: ?string, schema: ?string, context: array<int, string>, url: ?string}
     */
    private function actionOptions(array $flags): array
    {
        $text = fn (string $key): ?string => ($flags[$key] ?? null) === null ? null : (string) $flags[$key];

        return ['action' => $text('action'), 'schema' => $text('schema'), 'context' => array_values((array) ($flags['context'] ?? [])), 'url' => $text('url')];
    }

    /**
     * @param  array<string, mixed>  $flags
     * @param  array<int, string>  $kinds
     */
    private function checkAction(array $flags, array $kinds, bool $inertia): void
    {
        $given = array_keys(array_filter(array_intersect_key($flags, self::NEEDS_ACTION), $this->isGiven(...)));

        if (($flags['action'] ?? null) === null) {
            $this->refuseOrphans($given);

            return;
        }

        $this->refuseConflicts($kinds, $inertia);
    }

    /**
     * @param  array<int, string>  $given
     */
    private function refuseOrphans(array $given): void
    {
        if ($given !== []) {
            throw new RuntimeException("The option --{$given[0]} needs --action: ".self::NEEDS_ACTION[$given[0]].', e.g. --action=CreatePost.');
        }
    }

    /**
     * @param  array<int, string>  $kinds
     */
    private function refuseConflicts(array $kinds, bool $inertia): void
    {
        if ($kinds !== []) {
            throw new RuntimeException("The option --action cannot be combined with --{$kinds[0]}: it applies to the default invokable controller only.");
        }

        if ($inertia) {
            throw new RuntimeException('The option --action cannot be combined with --inertia: an Action controller returns back(), and Inertia page props built from DTOs are not generated yet.');
        }
    }

    /**
     * @param  array<int, string>  $kinds
     */
    private function kind(array $kinds): ControllerType
    {
        if (count($kinds) > 1) {
            throw new RuntimeException('The options '.$this->list($kinds).' cannot be combined: choose one controller kind.');
        }

        return $kinds === [] ? ControllerType::Invokable : self::KINDS[$kinds[0]];
    }

    /**
     * @param  array<int, string>  $kinds
     */
    private function checkInertia(array $kinds, bool $inertia): void
    {
        $conflict = array_values(array_intersect($kinds, ['api', 'standard']));

        if (! $inertia) {
            return;
        }

        if ($conflict !== []) {
            throw new RuntimeException("The option --inertia cannot be combined with --{$conflict[0]}: it applies to the default invokable controller and to --resource.");
        }
    }

    private function checkModel(ControllerType $type, ?string $model): void
    {
        if ($model === null) {
            return;
        }

        if (! $type->bindsModel()) {
            throw new RuntimeException(match ($type) {
                ControllerType::Standard => 'The option --model cannot be combined with --standard: it binds a model in the methods of --resource or --api.',
                default => 'The option --model needs --resource or --api: an invokable controller has no method that binds a model.',
            });
        }
    }

    /**
     * @param  array<int, string>  $kinds
     */
    private function list(array $kinds): string
    {
        $options = array_map(fn (string $kind): string => "--{$kind}", $kinds);
        $last = array_pop($options);

        return implode(', ', $options).' and '.$last;
    }

    private function isGiven(mixed $value): bool
    {
        return $value !== null && $value !== [];
    }
}
