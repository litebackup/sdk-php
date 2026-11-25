<?php

declare(strict_types=1);

namespace Litebackup;

use Closure;

/**
 * One request and how to decode its answer. timeoutSeconds is the
 * server-side timeout, which the client waits for plus
 * Wire::DEADLINE_GRACE_SECONDS; answerDeadlineSeconds replaces that
 * deadline, as a failover's role confirmation limit does.
 *
 * @template T
 */
final readonly class Call
{
    /**
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     * @param Closure(mixed): T $decoder
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        public ?array $body,
        public ?float $timeoutSeconds,
        public Closure $decoder,
        public ?float $answerDeadlineSeconds = null,
    ) {}

    /**
     * @template D
     * @param array<string, string> $query
     * @param Closure(mixed): D $decoder
     * @return self<D>
     */
    public static function get(string $path, array $query, Closure $decoder, ?float $timeoutSeconds = null): self
    {
        return new self('GET', $path, $query, null, $timeoutSeconds, $decoder);
    }

    /**
     * @template D
     * @param array<string, mixed> $body
     * @param Closure(mixed): D $decoder
     * @return self<D>
     */
    public static function post(string $path, array $body, Closure $decoder, ?float $timeoutSeconds = null): self
    {
        return new self('POST', $path, [], $body, $timeoutSeconds, $decoder);
    }

    /**
     * The same call with a deadline of its own.
     *
     * @return self<T>
     */
    public function withAnswerDeadline(float $seconds): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->body,
            $this->timeoutSeconds,
            $this->decoder,
            $seconds,
        );
    }

    /** The request target: the path and its query. */
    public function target(): string
    {
        if ($this->query === []) {
            return $this->path;
        }
        return $this->path . '?' . http_build_query($this->query, '', '&', PHP_QUERY_RFC3986);
    }

    /** How long the client waits for the answer, or null for as long as the daemon takes. */
    public function deadlineSeconds(): ?float
    {
        if ($this->answerDeadlineSeconds !== null) {
            return $this->answerDeadlineSeconds;
        }
        return $this->timeoutSeconds === null ? null : $this->timeoutSeconds + Wire::DEADLINE_GRACE_SECONDS;
    }

    /** The JSON body, or null for a GET; an empty body is an empty object. */
    public function encodedBody(): ?string
    {
        if ($this->body === null) {
            return null;
        }
        if ($this->body === []) {
            return '{}';
        }
        return json_encode($this->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
