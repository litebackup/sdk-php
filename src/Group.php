<?php

declare(strict_types=1);

namespace Litebackup;

/** The base of the client's groups. */
abstract class Group
{
    /** @internal */
    public function __construct(protected readonly Client $client) {}
}
