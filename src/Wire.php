<?php

declare(strict_types=1);

namespace Litebackup;

use Closure;
use DateTimeInterface;
use DateTimeZone;
use JsonException;
use Litebackup\Generated\AcquireResult;
use Litebackup\Generated\BackupList;
use Litebackup\Generated\BackupRule;
use Litebackup\Generated\DatabaseStatus;
use Litebackup\Generated\DatabaseStatusList;
use Litebackup\Generated\Durability;
use Litebackup\Generated\EventList;
use Litebackup\Generated\Failover as FailoverStatus;
use Litebackup\Generated\PrefixReplica;
use Litebackup\Generated\ReleaseResult;
use Litebackup\Generated\ReplicaList;
use Litebackup\Generated\ReplicaResult;
use Litebackup\Generated\RestoreResult;
use Litebackup\Generated\RevertResult;
use Litebackup\Generated\ShutdownResult;
use Litebackup\Generated\Status;
use Litebackup\Generated\StopBackupResult;
use Litebackup\Generated\VersionList;
use Litebackup\Generated\Writer;
use SplFileInfo;
use stdClass;
use Stringable;
use TypeError;
use UnexpectedValueException;
use ValueError;

/**
 * Request building and answer decoding, apart from the transport: every
 * operation is a method returning a Call, the request to send and how to
 * decode its answer.
 */
final class Wire
{
    public const SDK_VERSION_HEADER = 'Litebackup-SDK-Version';
    public const CLIENT_VERSION_HEADER = 'litebackup-client-version';
    public const SDK_VERSION_VALUE = 'php/' . Version::VERSION;
    /**
     * Added to a call's server-side timeout for its client-side deadline, so
     * the daemon's own timeout answer arrives first.
     */
    public const DEADLINE_GRACE_SECONDS = 30.0;
    public const EVENT_WAIT_SECONDS = 30.0;
    public const FIRST_RETRY_SECONDS = 0.25;
    public const MAXIMUM_RETRY_SECONDS = 10.0;
    /** Limits one answer. */
    public const RESPONSE_LIMIT = 64 << 20;
    public const JOURNAL_MODE_ROLLBACK = 'rollback';

    /** Errors that end an event watch instead of being retried. */
    public const TERMINAL_WATCH_CODES = [
        ErrorCode::SDK_UNSUPPORTED,
        ErrorCode::CLOSED,
        ErrorCode::CANCELLED,
        ErrorCode::INVALID_ARGUMENT,
        ErrorCode::CURSOR_EXPIRED,
    ];

    /** @return Closure(mixed): null */
    public static function nothing(): Closure
    {
        return static fn(mixed $value): mixed => null;
    }

    /**
     * Decodes an answer, throwing LitebackupError for anything but 200.
     *
     * @template T
     * @param Call<T> $call
     * @return T
     */
    public static function decodeAnswer(Call $call, int $status, string $body): mixed
    {
        if ($status !== 200) {
            throw self::responseError($status, $body);
        }
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            return ($call->decoder)($decoded);
        } catch (JsonException|UnexpectedValueException|TypeError|ValueError $error) {
            throw new LitebackupError(
                ErrorCode::INVALID_RESPONSE,
                'decoding ' . $call->path . ': ' . $error->getMessage(),
                $status,
                [],
                $error,
            );
        }
    }

    /**
     * A non-200 answer: a JSON object's code, message, and remaining fields,
     * or http_<status> with the text.
     */
    public static function responseError(int $status, string $body): LitebackupError
    {
        $text = self::validText($body);
        $fallback = new LitebackupError('http_' . $status, trim($text), $status);
        try {
            $shape = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $fallback;
        }
        if (!$shape instanceof stdClass || !is_array($decoded)) {
            return $fallback;
        }
        $code = $decoded['code'] ?? null;
        $message = $decoded['message'] ?? null;
        unset($decoded['code'], $decoded['message']);
        if (!is_string($code) || $code === '') {
            return is_string($message) ? new LitebackupError('http_' . $status, $message, $status) : $fallback;
        }
        $details = [];
        foreach ($decoded as $field => $value) {
            $details[(string) $field] = $value;
        }
        return new LitebackupError($code, is_string($message) ? $message : '', $status, $details);
    }

    /** The text with invalid UTF-8 replaced, so it can go into an error message. */
    public static function validText(string $bytes): string
    {
        if (preg_match('//u', $bytes) === 1) {
            return $bytes;
        }
        if (function_exists('mb_scrub')) {
            return mb_scrub($bytes, 'UTF-8');
        }
        return (string) preg_replace('/[\x80-\xff]/', '?', $bytes);
    }

    /**
     * Orders dotted numeric versions, ignoring any -suffix; parts that are
     * not numbers count as zero.
     */
    public static function compareVersions(string $left, string $right): int
    {
        $parts = static function (string $version): array {
            $release = explode('-', $version, 2)[0];
            return array_map(
                static fn(string $part): int => preg_match('/\A\d+\z/', $part) === 1 ? (int) $part : 0,
                explode('.', $release),
            );
        };
        $leftParts = $parts($left);
        $rightParts = $parts($right);
        $length = max(count($leftParts), count($rightParts));
        for ($index = 0; $index < $length; $index++) {
            $comparison = ($leftParts[$index] ?? 0) <=> ($rightParts[$index] ?? 0);
            if ($comparison !== 0) {
                return $comparison;
            }
        }
        return 0;
    }

    /** Refuses a daemon older than MINIMUM_DAEMON_VERSION, saying how to replace it. */
    public static function checkDaemonVersion(string $clientVersion, ?string $session): void
    {
        if ($clientVersion !== '' && self::compareVersions($clientVersion, Version::MINIMUM_DAEMON_VERSION) >= 0) {
            return;
        }
        $reported = $clientVersion === '' ? 'does not report its version' : 'is version ' . $clientVersion;
        $drain = $session !== null ? 'litebackup drain --session ' . $session : 'litebackup drain';
        throw new LitebackupError(
            ErrorCode::DAEMON_UNSUPPORTED,
            'the litebackup daemon ' . $reported . ', and this SDK needs ' . Version::MINIMUM_DAEMON_VERSION
            . ' or later. Stop it with `' . $drain . '` so the next call starts the new binary, '
            . 'or restart the service if `litebackup install service` runs it',
        );
    }

    /**
     * The connect probe every SDK sends.
     *
     * @return Call<Status>
     */
    public static function connectProbe(): Call
    {
        return Call::get('/v1/status/show', ['limit' => '1'], Status::fromWire(...));
    }

    /** Whether an optional argument holds something: not null and not ''. */
    public static function supplied(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    /** A path as text: a string, an SplFileInfo, or anything Stringable. */
    public static function pathText(mixed $source): string
    {
        if (is_string($source)) {
            return $source;
        }
        if ($source instanceof SplFileInfo) {
            return $source->getPathname();
        }
        if ($source instanceof Stringable) {
            return (string) $source;
        }
        throw LitebackupError::invalidArgument('expected a path, got ' . get_debug_type($source));
    }

    /**
     * Resolves a path against the working directory and cleans it
     * lexically; the daemon needs absolute paths. A ~ is a name like any other.
     */
    public static function absolutePath(mixed $source): string
    {
        $path = self::pathText($source);
        if (!str_starts_with($path, '/')) {
            $workingDirectory = getcwd();
            if ($workingDirectory === false) {
                throw LitebackupError::invalidArgument('the working directory cannot be read, so ' . $path . ' cannot be resolved');
            }
            $path = $workingDirectory . '/' . $path;
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return '/' . implode('/', $segments);
    }

    /**
     * A duration in seconds, or null when unset or zero, so the daemon's
     * default applies. Durations are seconds as an int or a float, such as
     * 90 or 1.5.
     */
    public static function seconds(mixed $duration, string $name): ?float
    {
        if ($duration === null) {
            return null;
        }
        if (!is_int($duration) && !is_float($duration)) {
            throw LitebackupError::invalidArgument($name . ' must be a number of seconds, got ' . get_debug_type($duration));
        }
        $durationSeconds = (float) $duration;
        if (is_nan($durationSeconds) || $durationSeconds < 0) {
            throw LitebackupError::invalidArgument($name . ' must not be negative');
        }
        if (is_infinite($durationSeconds)) {
            throw LitebackupError::invalidArgument($name . ' must be finite');
        }
        return $durationSeconds === 0.0 ? null : $durationSeconds;
    }

    public static function millis(?float $durationSeconds): ?int
    {
        return $durationSeconds === null ? null : (int) round($durationSeconds * 1000);
    }

    /** UTC with Z, fractional seconds only when they are not zero. */
    public static function wireTime(mixed $time): string
    {
        if (is_string($time)) {
            $parsed = WireModel::parseTime($time);
            if ($parsed === null) {
                throw LitebackupError::invalidArgument('expected an RFC 3339 time with its zone, got ' . json_encode($time));
            }
            $time = $parsed;
        }
        if (!$time instanceof DateTimeInterface) {
            throw LitebackupError::invalidArgument('expected a DateTimeInterface, got ' . get_debug_type($time));
        }
        $utc = \DateTimeImmutable::createFromInterface($time)->setTimezone(new DateTimeZone('UTC'));
        $text = $utc->format('Y-m-d\TH:i:s');
        $fraction = rtrim($utc->format('u'), '0');
        return $text . ($fraction === '' ? '' : '.' . $fraction) . 'Z';
    }

    /** An optional string argument, or null. */
    public static function optionalText(mixed $value, string $name): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }
        throw LitebackupError::invalidArgument($name . ' must be a string, got ' . get_debug_type($value));
    }

    /** A required string argument; it may be empty when the daemon answers for it. */
    public static function text(mixed $value, string $name): string
    {
        if (is_string($value)) {
            return $value;
        }
        throw LitebackupError::invalidArgument($name . ' must be a string, got ' . get_debug_type($value));
    }

    /** A true or false argument. */
    public static function flag(mixed $value, string $name): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        throw LitebackupError::invalidArgument($name . ' must be true or false, got ' . get_debug_type($value));
    }

    /** An optional whole-number argument, or null. */
    public static function optionalInteger(mixed $value, string $name): ?int
    {
        if ($value === null || is_int($value)) {
            return $value;
        }
        throw LitebackupError::invalidArgument($name . ' must be a whole number, got ' . get_debug_type($value));
    }

    /**
     * A request body without its unset fields: null, false, and '' are left out.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function compactBody(array $fields): array
    {
        return array_filter($fields, static fn(mixed $value): bool => $value !== null && $value !== false && $value !== '');
    }

    /** A page limit the daemon accepts: a whole number from 1 to 1000. */
    public static function pageLimit(mixed $limit): string
    {
        if (!is_int($limit) || $limit < 1 || $limit > 1000) {
            throw LitebackupError::invalidArgument('the limit must be a whole number from 1 to 1000');
        }
        return (string) $limit;
    }

    /** A page cursor as text: a string, or a whole number. */
    public static function cursor(mixed $after): string
    {
        if (is_string($after)) {
            return $after;
        }
        if (is_int($after)) {
            return (string) $after;
        }
        throw LitebackupError::invalidArgument('after must be a string cursor, got ' . get_debug_type($after));
    }

    /** @return Call<Status> */
    public static function status(mixed $after = '', mixed $limit = 100): Call
    {
        return Call::get(
            '/v1/status/show',
            ['after' => self::cursor($after), 'limit' => self::pageLimit($limit)],
            Status::fromWire(...),
        );
    }

    /** @return Call<DatabaseStatusList> */
    public static function databasesPage(mixed $after = '', mixed $limit = 100): Call
    {
        return Call::get(
            '/v1/databases/list',
            ['after' => self::cursor($after), 'limit' => self::pageLimit($limit)],
            DatabaseStatusList::fromWire(...),
        );
    }

    /** @return Call<DatabaseStatus> */
    public static function database(mixed $name): Call
    {
        return Call::get(
            '/v1/databases/status',
            ['name' => self::text($name, 'name')],
            DatabaseStatus::fromWire(...),
        );
    }

    /** @return Call<DatabaseStatus> */
    public static function sync(mixed $name): Call
    {
        return Call::post(
            '/v1/databases/sync',
            ['name' => self::text($name, 'name')],
            DatabaseStatus::fromWire(...),
        );
    }

    /** Checks an offload durability: null, server, or cloud. */
    public static function checkDurability(mixed $durability): ?string
    {
        if ($durability === null || (is_string($durability) && in_array($durability, Durability::ALL, true))) {
            return $durability;
        }
        throw LitebackupError::invalidArgument('offload durability must be server or cloud');
    }

    /**
     * Checks an offload durability and returns it, or null for the daemon's
     * default, server.
     */
    public static function offloadDurabilityChoice(mixed $durability): ?string
    {
        $checked = self::checkDurability($durability);
        return $checked === Durability::SERVER ? null : $checked;
    }

    /** @return Call<DatabaseStatus> */
    public static function offload(mixed $name, mixed $durability = null): Call
    {
        $body = ['name' => self::text($name, 'name')]
            + self::compactBody(['durability' => self::checkDurability($durability)]);
        return Call::post('/v1/databases/offload', $body, DatabaseStatus::fromWire(...));
    }

    /** @return Call<Writer> */
    public static function writer(mixed $name): Call
    {
        return Call::get(
            '/v1/databases/writer',
            ['name' => self::text($name, 'name')],
            Writer::fromWire(...),
        );
    }

    /** @return Call<VersionList> */
    public static function versionsPage(mixed $name, mixed $after = 0, mixed $limit = null): Call
    {
        $after = self::optionalInteger($after, 'after') ?? 0;
        $limit = self::optionalInteger($limit, 'limit');
        $query = ['name' => self::text($name, 'name')];
        if ($after > 0) {
            $query['after'] = (string) $after;
        }
        if ($limit !== null) {
            $query['limit'] = (string) $limit;
        }
        return Call::get('/v1/databases/versions', $query, VersionList::fromWire(...));
    }

    /** @return Call<RestoreResult> */
    public static function restore(
        mixed $name,
        mixed $path,
        mixed $version = null,
        mixed $time = null,
        mixed $umask = null,
    ): Call {
        if (!self::supplied($path)) {
            throw LitebackupError::invalidArgument('restore needs a path to write to');
        }
        if ($version !== null && $time !== null) {
            throw LitebackupError::invalidArgument('restore takes a version or a time, not both');
        }
        $body = ['name' => self::text($name, 'name'), 'path' => self::absolutePath($path)];
        $version = self::optionalInteger($version, 'version');
        if ($version !== null) {
            $body['version'] = $version;
        }
        if ($time !== null) {
            $body['time'] = self::wireTime($time);
        }
        $body['umask'] = Umask::requestUmask($umask);
        return Call::post('/v1/databases/restore', $body, RestoreResult::fromWire(...));
    }

    /** @return Call<RevertResult> */
    public static function revert(mixed $name, mixed $version = null, mixed $time = null): Call
    {
        if (($version === null) === ($time === null)) {
            throw LitebackupError::invalidArgument('revert takes a version or a time, one of them');
        }
        $body = ['name' => self::text($name, 'name')];
        if ($version !== null) {
            if (!is_int($version) || $version < 1) {
                throw LitebackupError::invalidArgument('revert needs a positive whole version');
            }
            $body['version'] = $version;
        }
        if ($time !== null) {
            $body['time'] = self::wireTime($time);
        }
        return Call::post('/v1/databases/revert', $body, RevertResult::fromWire(...));
    }

    /** @return Call<AcquireResult> */
    public static function acquire(
        mixed $name,
        mixed $path = null,
        mixed $maintenance = false,
        mixed $offloadOnRelease = false,
        mixed $offloadDurability = Durability::SERVER,
        mixed $machineLabel = null,
        mixed $timeout = null,
        mixed $lockTimeout = null,
        mixed $umask = null,
        mixed $discardLocal = false,
    ): Call {
        $timeoutSeconds = self::seconds($timeout, 'the acquire timeout');
        $lockTimeoutSeconds = self::seconds($lockTimeout, 'the lock timeout');
        // name is required, so it is sent even when empty and the daemon answers for it.
        $body = ['name' => self::text($name, 'name')] + self::compactBody([
            'path' => self::supplied($path) ? self::absolutePath($path) : null,
            'maintenance' => self::flag($maintenance, 'maintenance'),
            'discard_local' => self::flag($discardLocal, 'discardLocal'),
            'offload_on_release' => self::flag($offloadOnRelease, 'offloadOnRelease'),
            'offload_durability' => self::offloadDurabilityChoice($offloadDurability),
            'machine_label' => self::optionalText($machineLabel, 'machineLabel'),
            'timeout_millis' => self::millis($timeoutSeconds),
            'lock_timeout_millis' => self::millis($lockTimeoutSeconds),
        ]);
        $body['umask'] = Umask::requestUmask($umask);
        // No timeout leaves the call without a deadline, as the daemon applies its own.
        $callSeconds = $timeoutSeconds === null ? null : $timeoutSeconds + ($lockTimeoutSeconds ?? 0.0);
        return Call::post('/v1/databases/acquire', $body, AcquireResult::fromWire(...), $callSeconds);
    }

    /** @return Call<ReleaseResult> */
    public static function release(
        mixed $name,
        mixed $durability = null,
        mixed $offload = false,
        mixed $timeout = null,
    ): Call {
        $timeoutSeconds = self::seconds($timeout, 'the release timeout');
        $body = ['name' => self::text($name, 'name')] + self::compactBody([
            'durability' => self::optionalText($durability, 'durability'),
            'offload' => self::flag($offload, 'offload'),
            'timeout_millis' => self::millis($timeoutSeconds),
        ]);
        return Call::post('/v1/databases/release', $body, ReleaseResult::fromWire(...), $timeoutSeconds);
    }

    /** @return Call<BackupRule> */
    public static function startBackup(
        mixed $source,
        mixed $name = null,
        mixed $prefix = null,
        mixed $maximumLocalDatabases = null,
        mixed $offloadDurability = Durability::SERVER,
        mixed $offloadRollbackJournal = false,
    ): Call {
        if (!self::supplied($source)) {
            throw LitebackupError::invalidArgument('backup needs a path');
        }
        $name = self::optionalText($name, 'name');
        $prefix = self::optionalText($prefix, 'prefix');
        if (self::supplied($name) && self::supplied($prefix)) {
            throw LitebackupError::invalidArgument('choose a name or a prefix');
        }
        $maximum = self::optionalInteger($maximumLocalDatabases, 'maximumLocalDatabases');
        $body = self::compactBody([
            'path' => self::absolutePath($source),
            'name' => $name,
            'prefix' => $prefix,
            'maximum_local_databases' => $maximum === 0 ? null : $maximum,
            'offload_durability' => self::offloadDurabilityChoice($offloadDurability),
            'offload_rollback_journal' => self::flag($offloadRollbackJournal, 'offloadRollbackJournal'),
        ]);
        return Call::post('/v1/backup-rules/start', $body, BackupRule::fromWire(...));
    }

    /** @return Call<StopBackupResult> */
    public static function stopBackup(mixed $name): Call
    {
        if (!self::supplied($name)) {
            throw LitebackupError::invalidArgument('stopping a backup needs a name');
        }
        return Call::post(
            '/v1/backup-rules/stop',
            ['name' => self::text($name, 'name')],
            StopBackupResult::fromWire(...),
        );
    }

    /** @return Call<StopBackupResult> */
    public static function stopRule(mixed $ruleID): Call
    {
        if (!self::supplied($ruleID)) {
            throw LitebackupError::invalidArgument('stopping a rule needs its ID');
        }
        return Call::post(
            '/v1/backup-rules/stop',
            ['rule_id' => self::text($ruleID, 'the rule ID')],
            StopBackupResult::fromWire(...),
        );
    }

    /** @return Call<list<BackupRule>> */
    public static function listRules(): Call
    {
        return Call::get(
            '/v1/backup-rules/list',
            [],
            static fn(mixed $value): array => BackupList::fromWire($value)->rules,
        );
    }

    /** @return Call<DatabaseStatus> */
    public static function startReplica(
        mixed $name,
        mixed $path = null,
        mixed $writeProtection = null,
        mixed $timeout = null,
    ): Call {
        $timeoutSeconds = self::seconds($timeout, 'the replica timeout');
        $body = self::compactBody([
            'name' => self::text($name, 'name'),
            'path' => self::supplied($path) ? self::absolutePath($path) : null,
            'write_protection' => self::optionalText($writeProtection, 'writeProtection'),
            'timeout_millis' => self::millis($timeoutSeconds),
        ]);
        $decoder = static function (mixed $value): DatabaseStatus {
            $started = ReplicaResult::fromWire($value);
            if ($started->database === null) {
                throw new UnexpectedValueException('the daemon did not return the replica');
            }
            return $started->database;
        };
        return Call::post('/v1/replicas/start', $body, $decoder, $timeoutSeconds);
    }

    /** @return Call<PrefixReplica> */
    public static function startPrefixReplica(mixed $prefix, mixed $directory, mixed $writeProtection = null): Call
    {
        if (!self::supplied($directory)) {
            throw LitebackupError::invalidArgument('replicating a prefix needs a directory');
        }
        $body = ['prefix' => self::text($prefix, 'prefix')] + self::compactBody([
            'directory' => self::absolutePath($directory),
            'write_protection' => self::optionalText($writeProtection, 'writeProtection'),
        ]);
        $decoder = static function (mixed $value): PrefixReplica {
            $started = ReplicaResult::fromWire($value);
            if ($started->prefixReplica === null) {
                throw new UnexpectedValueException('the daemon did not return the prefix replica');
            }
            return $started->prefixReplica;
        };
        return Call::post('/v1/replicas/start', $body, $decoder);
    }

    /** @return Call<ReplicaList> */
    public static function listReplicas(): Call
    {
        return Call::get('/v1/replicas/list', [], ReplicaList::fromWire(...));
    }

    /** @return Call<null> */
    public static function stopReplica(mixed $name): Call
    {
        return Call::post('/v1/replicas/stop', ['name' => self::text($name, 'name')], self::nothing());
    }

    /** @return Call<null> */
    public static function stopPrefixReplica(mixed $prefix, mixed $directory): Call
    {
        if (!self::supplied($directory)) {
            throw LitebackupError::invalidArgument('stopping a prefix replica needs a directory');
        }
        return Call::post(
            '/v1/replicas/stop',
            ['prefix' => self::text($prefix, 'prefix'), 'directory' => self::absolutePath($directory)],
            self::nothing(),
        );
    }

    /** @return Call<EventList> */
    public static function eventsPage(mixed $after = 0, mixed $wait = null, mixed $limit = null): Call
    {
        $waitSeconds = self::seconds($wait, 'the event wait');
        $after = self::optionalInteger($after, 'after') ?? 0;
        $limit = self::optionalInteger($limit, 'limit');
        $query = [];
        if ($after !== 0) {
            $query['after'] = (string) $after;
        }
        if ($waitSeconds !== null) {
            $query['wait_millis'] = (string) self::millis($waitSeconds);
        }
        if ($limit !== null && $limit !== 0) {
            $query['limit'] = (string) $limit;
        }
        return Call::get('/v1/events/list', $query, EventList::fromWire(...), $waitSeconds);
    }

    /** @return Call<ShutdownResult> */
    public static function shutdown(mixed $durability = null, mixed $timeout = null): Call
    {
        $timeoutSeconds = self::seconds($timeout, 'the shutdown timeout');
        $body = self::compactBody([
            'durability' => self::optionalText($durability, 'durability'),
            'timeout_millis' => self::millis($timeoutSeconds),
        ]);
        return Call::post('/v1/session/shutdown', $body, ShutdownResult::fromWire(...), $timeoutSeconds);
    }

    /** @return Call<FailoverStatus> */
    public static function startFailover(
        mixed $path,
        mixed $name = null,
        mixed $timeout = null,
        mixed $discardLocal = false,
    ): Call {
        if (!self::supplied($path)) {
            throw LitebackupError::invalidArgument('failover needs a path');
        }
        $timeoutSeconds = self::seconds($timeout, 'the failover timeout');
        $body = self::compactBody([
            'path' => self::absolutePath($path),
            'name' => self::optionalText($name, 'name'),
            'timeout_millis' => self::millis($timeoutSeconds),
            'discard_local' => self::flag($discardLocal, 'discardLocal'),
        ]);
        return Call::post('/v1/failovers/start', $body, FailoverStatus::fromWire(...), $timeoutSeconds);
    }

    public static function terminalWatchError(LitebackupError $error): bool
    {
        return in_array($error->errorCode, self::TERMINAL_WATCH_CODES, true);
    }
}
