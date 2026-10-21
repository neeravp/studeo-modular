<?php

namespace Studeo\Modular\Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

/**
 * Reads the public documents of the package so that tests can compare what they state with the code.
 */
final class PackageDocs
{
    /**
     * @return array<string, Command> the commands of this package, by name
     */
    public static function commands(): array
    {
        $commands = [];
        foreach (Artisan::all() as $command) {
            $isOurs = str_starts_with((string) $command->getName(), 'module:') || str_starts_with((string) $command->getName(), 'modular:');
            $commands = $isOurs ? [...$commands, (string) $command->getName() => $command] : $commands;
        }

        return $commands;
    }

    public static function read(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2)."/{$file}");
    }

    /**
     * @return list<string> the first capture group of every match, in order
     */
    public static function matches(string $pattern, string $text): array
    {
        preg_match_all($pattern, $text, $found);

        return $found[1];
    }

    /**
     * @param  array<int, string>  $values
     * @return list<string>
     */
    public static function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * The text of one section: from its heading to the next heading of the same or a higher level.
     */
    public static function section(string $file, string $heading): string
    {
        $text = self::read($file);
        $start = strpos($text, $heading);
        $level = strlen($heading) - strlen(ltrim($heading, '#'));
        $rest = substr($text, $start === false ? 0 : $start + strlen($heading));
        $next = preg_match('/^#{1,'.$level.'} /m', $rest, $found, PREG_OFFSET_CAPTURE);

        return $next === 1 ? substr($rest, 0, $found[0][1]) : $rest;
    }

    /**
     * The backticked entries of the bullet that starts with a label.
     *
     * @return list<string>
     */
    public static function bulletEntries(string $text, string $label): array
    {
        $found = preg_match('/^- '.preg_quote($label, '/').'[^:\n]*: (.+)$/m', $text, $match);

        return $found === 1 ? self::matches('/`([^`]+)`/', $match[1]) : [];
    }
}
