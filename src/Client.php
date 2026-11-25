<?php

declare(strict_types=1);

namespace Litebackup;

use JsonException;

/**
 * Talks to the Litebackup daemon of one session over its unix socket,
 * starting it like the CLI does. It connects on first use. close() never
 * shuts the daemon down:
 *
 *     $litebackup = new Litebackup\Client();
 *     try {
 *         $litebackup->backups->start('/var/lib/app/main.sqlite');
 *     } finally {
 *         $litebackup->close();
 *     }
 *
 * A client belongs to the PHP process that made it. One made before a fork
 * is refused in the child with invalid_argument, and closing it there only
 * closes the child's descriptors; make a new client in the child.
 */
final class Client
{
    public readonly Backups $backups;
    public readonly Rules $rules;
    public readonly Databases $databases;
    public readonly Replicas $replicas;
    public readonly Events $events;
    public readonly Session $session;
    public readonly Failovers $failovers;

    private readonly ClientSettings $settings;
    private readonly Transport $transport;
    private readonly int $ownerPID;
    private bool $connected = false;
    private bool $closed = false;
    private string $clientVersion = '';
    /** @var array<int, ChildCommand> the foreground jobs running, by object ID */
    private array $jobs = [];
    /** @var array<int, Failover> the failovers this client started, by object ID */
    private array $startedFailovers = [];

    /**
     * @param string|null $apiKey the credential the daemon uses when it
     *     starts: an API key, or a join token it exchanges once for a key of
     *     this machine, then @ and the server's address; LITEBACKUP_API_KEY
     *     by default, and '' for none. The daemon keeps the key it saved
     *     unless this holds a different credential, which the launch refuses
     *     with drain_required while the client has write locks; a daemon
     *     already running keeps its key until it restarts. Foreground
     *     backups use it too.
     * @param string|null $session the session name; LITEBACKUP_SESSION, then default.
     * @param string|\Stringable|\SplFileInfo|null $socketPath connect to this socket and never start a daemon.
     * @param string|\Stringable|\SplFileInfo|null $binaryPath the litebackup binary that starts the daemon;
     *     LITEBACKUP_PATH, then litebackup on PATH, then ~/.local/bin/litebackup.
     * @param bool $launch start the session daemon when it is not running.
     * @param array<string, string>|null $environment the environment for resolving paths and
     *     starting the daemon; this process's by default.
     */
    public function __construct(
        mixed $apiKey = null,
        mixed $session = null,
        mixed $socketPath = null,
        mixed $binaryPath = null,
        mixed $launch = true,
        ?array $environment = null,
    ) {
        $this->settings = new ClientSettings($apiKey, $session, $socketPath, $binaryPath, $launch, $environment);
        $this->transport = new Transport($this->settings->socketPath);
        $this->ownerPID = getmypid() ?: 0;
        $this->backups = new Backups($this);
        $this->rules = new Rules($this);
        $this->databases = new Databases($this);
        $this->replicas = new Replicas($this);
        $this->events = new Events($this);
        $this->session = new Session($this);
        $this->failovers = new Failovers($this);
    }

    /** The daemon's version, from its last answer. */
    public function clientVersion(): string
    {
        return $this->clientVersion;
    }

    public function socketPath(): string
    {
        return $this->settings->socketPath;
    }

    /** The session name, or null for a client of an explicit socket path. */
    public function sessionName(): ?string
    {
        return $this->settings->session;
    }

    public function closed(): bool
    {
        return $this->closed;
    }

    /**
     * Connects, starting the daemon when needed; fails with
     * daemon_unsupported for a daemon older than 1.5.0.
     */
    public function connect(): void
    {
        $this->connectBefore(null);
    }

    /**
     * Connects unless connected, with the connect probe bounded by the
     * deadline, a monotonic time, of the call that connects.
     */
    private function connectBefore(?float $deadline): void
    {
        $this->ensureOpen();
        if ($this->connected) {
            return;
        }
        $settings = $this->settings;
        if (!$settings->explicitSocket && $settings->launch && $settings->session !== null) {
            Launch::launchDaemon(
                $settings->session,
                $settings->socketPath,
                $settings->environment,
                $settings->apiKey,
                $settings->binaryPath,
            );
        }
        $probe = Wire::connectProbe();
        $this->send($probe, null, self::secondsLeft($deadline));
        Wire::checkDaemonVersion($this->clientVersion, $settings->session);
        $this->connected = true;
    }

    /**
     * Ends running foreground backups, makes the failovers it started
     * unknown, and closes connections. The daemon keeps running, and so do
     * its failovers and acquired databases. A second close does nothing.
     */
    public function close(): void
    {
        $this->closed = true;
        $this->transport->close();
        // A forked child has no jobs or failovers of its own.
        if ($this->ownerPID !== getmypid()) {
            return;
        }
        foreach ($this->startedFailovers as $failover) {
            $failover->clientClosed();
        }
        $this->startedFailovers = [];
        foreach ($this->jobs as $job) {
            Foreground::stopJob($job);
        }
    }

    /**
     * Throws closed after close, and invalid_argument in a forked child.
     *
     * @internal
     */
    public function ensureOpen(): void
    {
        if ($this->closed) {
            throw new LitebackupError(ErrorCode::CLOSED, 'the client is closed');
        }
        if ($this->ownerPID === getmypid()) {
            return;
        }
        throw LitebackupError::invalidArgument(
            'this client was made in process ' . $this->ownerPID . ' and cannot be used after a fork; '
            . 'make a new client in this process',
        );
    }

    /**
     * Sends a call without connecting first.
     *
     * @internal
     * @template T
     * @param Call<T> $call
     * @return T
     */
    public function sendCall(Call $call): mixed
    {
        return $this->send($call, self::encodedBody($call), $call->deadlineSeconds());
    }

    /**
     * Connects, then sends a call.
     *
     * @internal
     * @template T
     * @param Call<T> $call
     * @return T
     */
    public function call(Call $call): mixed
    {
        // Encoded first, so an argument JSON cannot carry fails before
        // anything is launched or sent.
        $body = self::encodedBody($call);
        $deadlineSeconds = $call->deadlineSeconds();
        $deadline = $deadlineSeconds === null ? null : Clock::now() + $deadlineSeconds;
        $this->connectBefore($deadline);
        return $this->send($call, $body, self::secondsLeft($deadline));
    }

    /**
     * @template T
     * @param Call<T> $call
     * @return T
     */
    private function send(Call $call, ?string $body, ?float $deadlineSeconds): mixed
    {
        $this->ensureOpen();
        $answer = $this->transport->request($call->method, $call->target(), $body, $deadlineSeconds);
        $this->clientVersion = $answer->clientVersion;
        return Wire::decodeAnswer($call, $answer->status, $answer->body);
    }

    /** @param Call<mixed> $call */
    private static function encodedBody(Call $call): ?string
    {
        try {
            return $call->encodedBody();
        } catch (JsonException $error) {
            throw new LitebackupError(
                ErrorCode::INVALID_ARGUMENT,
                'the request to ' . $call->path . ' cannot be sent as JSON: ' . $error->getMessage(),
                0,
                [],
                $error,
            );
        }
    }

    /**
     * What is left of a call's deadline, a monotonic time: null without a
     * deadline, and timeout once nothing is left.
     */
    private static function secondsLeft(?float $deadline): ?float
    {
        if ($deadline === null) {
            return null;
        }
        $left = $deadline - Clock::now();
        if ($left <= 0) {
            throw new LitebackupError(
                ErrorCode::TIMEOUT,
                "the daemon did not answer within the call's timeout; it may still finish the operation",
            );
        }
        return $left;
    }

    /** @internal */
    public function settings(): ClientSettings
    {
        return $this->settings;
    }

    /**
     * Registers a running job so close stops it.
     *
     * @internal
     */
    public function addJob(ChildCommand $job): void
    {
        $this->jobs[spl_object_id($job)] = $job;
    }

    /** @internal */
    public function removeJob(ChildCommand $job): void
    {
        unset($this->jobs[spl_object_id($job)]);
    }

    /** @internal */
    public function addFailover(Failover $failover): void
    {
        $this->startedFailovers[spl_object_id($failover)] = $failover;
    }

    /** @internal */
    public function removeFailover(Failover $failover): void
    {
        unset($this->startedFailovers[spl_object_id($failover)]);
    }
}
