<?php

declare(strict_types=1);

namespace Litebackup\Tests\Support;

use DateTimeImmutable;
use Litebackup\Client;
use RuntimeException;
use SplFileInfo;

/**
 * The language-neutral fixtures in testdata/contract, which the Go,
 * TypeScript, Python, and Ruby SDKs run too, and how their operations map
 * to the PHP API: methods in camelCase, the first argument positional, the
 * rest named in camelCase, durations in seconds, times as
 * DateTimeImmutable, and {"$path": ...} as an SplFileInfo.
 */
final class ContractFixtures
{
    public const ROOT = __DIR__ . '/../../../testdata/contract';

    /** The positional argument of each operation that takes one. */
    private const POSITIONAL = [
        'backups.start' => 'source',
        'replicas.start_prefix' => 'prefix',
        'replicas.stop_prefix' => 'prefix',
        'rules.stop' => 'id',
    ];
    /** Operations without a positional argument. */
    private const NO_POSITIONAL = ['session.status', 'session.shutdown', 'databases.page', 'events.page', 'rules.list', 'replicas.list'];
    private const DURATION_KEYS = ['timeout', 'lock_timeout', 'wait', 'cleanup_timeout'];

    /** @return list<string> */
    public static function names(string $fixtureDirectory): array
    {
        $names = array_map(
            static fn(string $path): string => basename($path, '.json'),
            glob(self::ROOT . '/' . $fixtureDirectory . '/*.json') ?: [],
        );
        sort($names);
        return $names;
    }

    /**
     * A data provider's rows: one per fixture, keyed by its name.
     *
     * @return array<string, array{string}>
     */
    public static function provide(string $fixtureDirectory): array
    {
        $fixtureCases = [];
        foreach (self::names($fixtureDirectory) as $name) {
            $fixtureCases[$name] = [$name];
        }
        return $fixtureCases;
    }

    /** This process's umask, read by setting it and putting it back, apart from how the SDK reads it. */
    public static function fixtureUmask(): int
    {
        $previous = umask(0);
        umask($previous);
        return $previous;
    }

    /** @return array<string, mixed> */
    public static function read(string $fixtureDirectory, string $name, string $cwd = '/'): array
    {
        $text = (string) file_get_contents(self::ROOT . '/' . $fixtureDirectory . '/' . $name . '.json');
        $text = str_replace('{cwd}', substr(json_encode($cwd, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 1, -1), $text);
        $text = str_replace('"{umask}"', (string) self::fixtureUmask(), $text);
        /** @var array<string, mixed> */
        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A fixture field that holds text.
     *
     * @param array<string, mixed> $fixture
     */
    public static function text(array $fixture, string $key): string
    {
        $value = $fixture[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException($key . ' is not text in the fixture');
        }
        return $value;
    }

    /**
     * The reason a fixture skips this SDK, or null.
     *
     * @param array<string, mixed> $fixture
     */
    public static function skipReason(array $fixture): ?string
    {
        $skip = $fixture['skip'] ?? null;
        return is_array($skip) && is_string($skip['php'] ?? null) ? $skip['php'] : null;
    }

    /** A fixture duration such as 1500ms or -1s, in seconds. */
    public static function duration(mixed $text): ?float
    {
        if ($text === null) {
            return null;
        }
        if (!is_string($text) || preg_match('/\A(-?\d+(?:\.\d+)?)(ms|s|m|h)\z/', $text, $match) !== 1) {
            throw new RuntimeException('bad duration ' . json_encode($text));
        }
        $unit = ['ms' => 0.001, 's' => 1, 'm' => 60, 'h' => 3600][$match[2]];
        return (float) $match[1] * $unit;
    }

    /** A snake_case name in camelCase. */
    public static function camelCase(string $name): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $name))));
    }

    /** {"$path": ...} becomes a relative SplFileInfo, which resolves against the working directory. */
    public static function pathArgument(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('$path', $value) && is_string($value['$path'])) {
            return new SplFileInfo($value['$path']);
        }
        return $value;
    }

    /**
     * Runs a fixture operation on a client.
     *
     * @param array<string, mixed> $args
     */
    public static function invoke(Client $client, string $operation, array $args): mixed
    {
        [$group, $method] = explode('.', $operation, 2);
        $positional = [];
        if (!in_array($operation, self::NO_POSITIONAL, true)) {
            $key = self::POSITIONAL[$operation] ?? 'name';
            $positional[] = self::pathArgument($args[$key] ?? null);
            unset($args[$key]);
        }
        $named = [];
        foreach ($args as $key => $value) {
            $named[self::camelCase($key)] = match (true) {
                in_array($key, self::DURATION_KEYS, true) => self::duration($value),
                $key === 'time' && is_string($value) => new DateTimeImmutable($value),
                default => self::pathArgument($value),
            };
        }
        $target = $client->{$group};
        $callable = [$target, self::camelCase($method)];
        if (!is_callable($callable)) {
            throw new RuntimeException('no PHP method for ' . $operation);
        }
        return $callable(...$positional, ...$named);
    }
}
