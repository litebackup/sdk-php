<?php

declare(strict_types=1);

namespace Litebackup;

/**
 * One connection to the daemon and the bytes read past the last answer.
 *
 * @internal
 */
final class TransportConnection
{
    /** @var resource|null */
    public $stream = null;
    public string $buffer = '';
    /** Whether any byte of the current answer arrived, after which a GET is never sent again. */
    public bool $answerStarted = false;

    /** @return resource */
    public function requireStream()
    {
        if ($this->stream === null) {
            throw new TransportConnectionBroken('the connection is not open');
        }
        return $this->stream;
    }

    /** An idle connection the daemon closed, or that holds bytes nobody asked for, reads as ready. */
    public function dropped(): bool
    {
        if ($this->stream === null || feof($this->stream)) {
            return true;
        }
        $readable = [$this->stream];
        $writable = null;
        $failed = null;
        $ready = @stream_select($readable, $writable, $failed, 0, 0);
        return $ready !== 0;
    }

    /** Closes the descriptor; no socket shutdown, so a forked parent's connection lives on. */
    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }
}
