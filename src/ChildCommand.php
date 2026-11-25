<?php

declare(strict_types=1);

namespace Litebackup;

/**
 * A command running as a child of this PHP process, as litebackup launch and
 * foreground backups run, through proc_open with an exact environment.
 * PHP has no threads, so the wait reads the command's output pipes in the
 * same loop that watches for its exit.
 */
final class ChildCommand
{
    /** How often a wait without pipes to read looks at the command's status. */
    private const POLL_SECONDS = 0.05;
    private const READ_SIZE = 65536;
    /** proc_open's exit status when exec failed: the file cannot run. */
    private const EXEC_FAILED_STATUS = 127;
    public const SIGNAL_TERMINATE = 15;
    public const SIGNAL_KILL = 9;

    /** @var resource */
    private $command;
    /** @var array<int, resource> the output pipes still open, by descriptor */
    private array $pipes;
    /** @var array<int, string> what each output pipe delivered, by descriptor */
    private array $received = [1 => '', 2 => ''];
    private ?int $exitStatus = null;
    private ?int $terminationSignal = null;
    private readonly int $ownerPID;

    /**
     * @param resource $command
     * @param array<int, resource> $pipes
     */
    private function __construct($command, array $pipes, public readonly string $binary)
    {
        $this->command = $command;
        $this->pipes = $pipes;
        $this->ownerPID = getmypid() ?: 0;
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
    }

    /**
     * Starts a command with exactly environment, its input from /dev/null,
     * its error output read, and its standard output read or discarded.
     * proc_open leaves out a variable whose value is empty, which every
     * litebackup command reads as unset, as an empty credential is none.
     *
     * @param non-empty-list<string> $command
     * @param array<string, string> $environment
     */
    public static function start(array $command, array $environment, bool $readOutput): self
    {
        $binary = $command[0];
        if (!Binary::missingPath($binary) && !(is_file($binary) && is_executable($binary))) {
            throw new LitebackupError(
                ErrorCode::LAUNCH_FAILED,
                'starting ' . $binary . ' failed: it is not an executable file',
            );
        }
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => $readOutput ? ['pipe', 'w'] : ['file', '/dev/null', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $started = @proc_open($command, $descriptors, $pipes, null, $environment);
        if ($started === false) {
            $failure = error_get_last()['message'] ?? 'proc_open failed';
            throw new LitebackupError(ErrorCode::LAUNCH_FAILED, Binary::startFailureMessage($binary, $failure));
        }
        /** @var array<int, resource> $pipes */
        return new self($started, $pipes, $binary);
    }

    /** The command's exit status, or null while it runs or when a signal ended it. */
    public function exitStatus(): ?int
    {
        $this->updateStatus();
        return $this->exitStatus;
    }

    /** The signal that ended the command, or null. */
    public function terminationSignal(): ?int
    {
        $this->updateStatus();
        return $this->terminationSignal;
    }

    public function exited(): bool
    {
        $this->updateStatus();
        return $this->exitStatus !== null || $this->terminationSignal !== null;
    }

    /**
     * Whether exec failed: the command exited 127 at once with no output, as
     * proc_open reports a file that is there but cannot run.
     */
    public function execFailed(): bool
    {
        return $this->exitStatus() === self::EXEC_FAILED_STATUS
            && $this->received[1] === '' && $this->received[2] === '';
    }

    public function signal(int $signal): void
    {
        if (!$this->exited()) {
            @proc_terminate($this->command, $signal);
        }
    }

    /**
     * Waits for the command to exit, reading its output meanwhile, for up
     * to seconds or, with null, as long as it runs. Returns whether it exited.
     */
    public function awaitExit(?float $seconds): bool
    {
        $deadline = $seconds === null ? null : Clock::now() + $seconds;
        while (!$this->exited()) {
            $left = $deadline === null ? self::POLL_SECONDS : min(self::POLL_SECONDS, $deadline - Clock::now());
            if ($left <= 0) {
                return false;
            }
            $this->readAvailable($left);
        }
        return true;
    }

    /**
     * Reads what the output pipes still hold, until each ends or seconds
     * pass, as a process the command started may keep them open.
     */
    public function drain(float $seconds): void
    {
        $deadline = Clock::now() + $seconds;
        while ($this->pipes !== []) {
            $left = $deadline - Clock::now();
            if ($left <= 0) {
                break;
            }
            $this->readAvailable($left);
        }
        $this->closePipes();
    }

    /** What the command wrote to its standard output, as valid UTF-8. */
    public function output(): string
    {
        return Wire::validText($this->received[1]);
    }

    /** What the command wrote to its error output, as valid UTF-8. */
    public function errorOutput(): string
    {
        return Wire::validText($this->received[2]);
    }

    /**
     * Closes the pipes and reaps the command once it exited. In a forked
     * child the parent's command is not reaped or signalled.
     */
    public function close(): void
    {
        $this->closePipes();
        if ($this->ownerPID === getmypid() && $this->exited()) {
            @proc_close($this->command);
        }
    }

    /** Reads whatever the open pipes have within seconds; a pipe that ended is closed. */
    private function readAvailable(float $seconds): void
    {
        if ($this->pipes === []) {
            usleep((int) ($seconds * 1_000_000));
            return;
        }
        $readable = array_values($this->pipes);
        $writable = null;
        $failed = null;
        $whole = (int) floor($seconds);
        $ready = @stream_select($readable, $writable, $failed, $whole, (int) (($seconds - $whole) * 1_000_000));
        if ($ready === false || $ready === 0) {
            return;
        }
        foreach ($this->pipes as $descriptor => $pipe) {
            if (!in_array($pipe, $readable, true)) {
                continue;
            }
            $chunk = @fread($pipe, self::READ_SIZE);
            if ($chunk === false || ($chunk === '' && feof($pipe))) {
                fclose($pipe);
                unset($this->pipes[$descriptor]);
                continue;
            }
            $this->received[$descriptor] .= $chunk;
        }
    }

    private function closePipes(): void
    {
        foreach ($this->pipes as $pipe) {
            @fclose($pipe);
        }
        $this->pipes = [];
    }

    /** Saves the exit status the first time proc_get_status reports it, which it does once. */
    private function updateStatus(): void
    {
        if ($this->exitStatus !== null || $this->terminationSignal !== null) {
            return;
        }
        $status = @proc_get_status($this->command);
        if ($status['running']) {
            return;
        }
        if ($status['signaled']) {
            $this->terminationSignal = $status['termsig'];
            return;
        }
        $this->exitStatus = $status['exitcode'];
    }
}
