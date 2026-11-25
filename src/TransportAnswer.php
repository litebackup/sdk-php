<?php

declare(strict_types=1);

namespace Litebackup;

/**
 * A whole answer read from the socket.
 *
 * @internal
 */
final readonly class TransportAnswer
{
    public function __construct(
        public int $status,
        public string $clientVersion,
        public string $body,
    ) {}
}
