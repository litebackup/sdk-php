<?php

declare(strict_types=1);

namespace Litebackup;

use Litebackup\Generated\BackupRule;
use Litebackup\Generated\Durability;
use Litebackup\Generated\StopBackupResult;

/** Continuous backups and one-shot backup jobs. */
final class Backups extends Group
{
    /**
     * Saves a continuous backup rule for a file, directory, or glob; returns
     * once the daemon saved it. Starting the same path again with the same
     * settings returns the saved rule, so call it on every start. Different
     * settings for a saved path throw rule_exists with the saved rule in
     * details['rule'].
     *
     * @param string|\Stringable|\SplFileInfo $source
     * @param string|null $name
     * @param string|null $prefix
     * @param int|null $maximumLocalDatabases
     * @param string $offloadDurability Durability::SERVER or Durability::CLOUD
     * @param bool $offloadRollbackJournal
     */
    public function start(
        mixed $source,
        mixed $name = null,
        mixed $prefix = null,
        mixed $maximumLocalDatabases = null,
        mixed $offloadDurability = Durability::SERVER,
        mixed $offloadRollbackJournal = false,
    ): BackupRule {
        return $this->client->call(Wire::startBackup(
            $source,
            $name,
            $prefix,
            $maximumLocalDatabases,
            $offloadDurability,
            $offloadRollbackJournal,
        ));
    }

    /**
     * Runs one foreground backup job and returns once it exits, after the
     * chosen destination acknowledged it. With a timeout, a job still running
     * 30 seconds after it ran out is killed and throws timeout.
     *
     * @param string|\Stringable|\SplFileInfo $source
     * @param string|null $name
     * @param string|null $prefix
     * @param string $durability Durability::SERVER or Durability::CLOUD
     * @param int|float|null $timeout seconds
     */
    public function once(
        mixed $source,
        mixed $name = null,
        mixed $prefix = null,
        mixed $durability = Durability::SERVER,
        mixed $timeout = null,
    ): void {
        $this->client->ensureOpen();
        $settings = $this->client->settings();
        $command = $settings->foregroundCommand($source, $name, $prefix, $durability, $timeout);
        $deadline = Foreground::jobDeadline($timeout);
        $job = Foreground::startJob($command, $settings->foregroundEnvironment());
        $this->client->addJob($job);
        $finished = false;
        try {
            $timedOut = !$job->awaitExit($deadline);
            if ($timedOut) {
                $job->signal(ChildCommand::SIGNAL_KILL);
                $job->awaitExit(null);
            }
            $job->drain(Foreground::DRAIN_SECONDS);
            $finished = true;
        } finally {
            // An exception from inside the wait, such as one a signal
            // handler threw, never leaves the job running.
            if (!$finished) {
                $job->signal(ChildCommand::SIGNAL_KILL);
                $job->awaitExit(null);
            }
            $this->client->removeJob($job);
            $job->close();
        }
        if ($timedOut) {
            throw Foreground::timedOutBackup();
        }
        $status = $job->exitStatus();
        if ($status === 0) {
            return;
        }
        if ($this->client->closed()) {
            throw new LitebackupError(ErrorCode::CLOSED, 'the client is closed');
        }
        if ($job->execFailed()) {
            throw Foreground::startFailure($command[0], 'it could not be started');
        }
        $exit = $status === null ? 'signal ' . $job->terminationSignal() : 'exit status ' . $status;
        throw Foreground::backupFailure($job->errorOutput(), $exit);
    }

    /**
     * Stops the single-file backup with this exact remote name on this machine.
     *
     * @param string $name
     */
    public function stop(mixed $name): StopBackupResult
    {
        return $this->client->call(Wire::stopBackup($name));
    }
}
