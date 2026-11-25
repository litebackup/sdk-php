<?php

declare(strict_types=1);

namespace Litebackup\Integrations;

use Litebackup\ErrorCode;
use Litebackup\LitebackupError;
use Litebackup\Wire;

/** SQLite filenames and file: URIs, resolved as sqlite3ParseUri does. */
final class SqliteFile
{
    public const NO_FILE = 'an in-memory or temporary database has no file to back up; open it from a path';

    /** The absolute file a file: URI opens, against base for a relative path. */
    public static function fileFromUri(string $uri, string $base): string
    {
        $rest = substr($uri, strlen('file:'));
        if (str_starts_with($rest, '//')) {
            $authorityEnd = strcspn($rest, '/?#', 2) + 2;
            $authority = substr($rest, 2, $authorityEnd - 2);
            if ($authority !== '' && $authority !== 'localhost') {
                throw LitebackupError::invalidArgument(
                    'SQLite opens only local files, and ' . json_encode($uri) . ' names host ' . json_encode($authority),
                );
            }
            $rest = substr($rest, $authorityEnd);
        }
        $withoutFragment = explode('#', $rest, 2)[0];
        [$encodedPath, $query] = array_pad(explode('?', $withoutFragment, 2), 2, '');
        foreach (explode('&', $query) as $part) {
            if ($part === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $pair = [rawurldecode($key), rawurldecode($value)];
            if ($pair === ['mode', 'memory'] || $pair === ['vfs', 'memdb']) {
                throw LitebackupError::invalidArgument(self::NO_FILE);
            }
        }
        return self::plainFile(rawurldecode($encodedPath), $base);
    }

    /** The absolute file a plain SQLite filename opens; '' and ':memory:' open none. */
    public static function plainFile(string $filename, string $base): string
    {
        if ($filename === '' || $filename === ':memory:') {
            throw LitebackupError::invalidArgument(self::NO_FILE);
        }
        return Wire::absolutePath(str_starts_with($filename, '/') ? $filename : $base . '/' . $filename);
    }

    /** The file of a SQLite filename or file: URI. */
    public static function databaseFile(string $filename, string $base): string
    {
        return str_starts_with($filename, 'file:')
            ? self::fileFromUri($filename, $base)
            : self::plainFile($filename, $base);
    }

    /**
     * The file of one schema in PRAGMA database_list entries of seq, name, and file.
     *
     * @param iterable<mixed> $entries
     */
    public static function fileFromDatabaseList(iterable $entries, string $schema): string
    {
        foreach ($entries as $entry) {
            if (!is_array($entry) || ($entry['name'] ?? $entry[1] ?? null) !== $schema) {
                continue;
            }
            $file = $entry['file'] ?? $entry[2] ?? null;
            if (!is_string($file) || $file === '') {
                throw LitebackupError::invalidArgument(
                    'the database ' . json_encode($schema) . ' is in memory or temporary and has no file to back up',
                );
            }
            return $file;
        }
        throw LitebackupError::invalidArgument('no attached database named ' . json_encode($schema));
    }

    /** Throws not_found unless something is at path: a pool's adapter opens existing databases only. */
    public static function requireExistingFile(string $path): void
    {
        clearstatcache(true, $path);
        if (file_exists($path)) {
            return;
        }
        throw new LitebackupError(ErrorCode::NOT_FOUND, $path . ' does not exist; the adapter opens existing databases only');
    }
}
