<?php

declare(strict_types=1);

namespace Litebackup;

use Litebackup\Generated\DatabaseStatus;
use Litebackup\Generated\PrefixReplica;
use Litebackup\Generated\ReplicaList;

/** Live readonly replicas on this machine. */
final class Replicas extends Group
{
    /**
     * Keeps a live readonly replica and returns once its first version is local.
     *
     * @param string $name
     * @param string|\Stringable|\SplFileInfo|null $path
     * @param string|null $writeProtection WriteProtection::LOCK, DETECT, or NONE
     * @param int|float|null $timeout seconds
     */
    public function start(
        mixed $name,
        mixed $path = null,
        mixed $writeProtection = null,
        mixed $timeout = null,
    ): DatabaseStatus {
        return $this->client->call(Wire::startReplica($name, $path, $writeProtection, $timeout));
    }

    /**
     * Replicates current and future databases under a prefix into a
     * directory; downloads go on in the background.
     *
     * @param string $prefix
     * @param string|\Stringable|\SplFileInfo $directory
     * @param string|null $writeProtection
     */
    public function startPrefix(mixed $prefix, mixed $directory = null, mixed $writeProtection = null): PrefixReplica
    {
        return $this->client->call(Wire::startPrefixReplica($prefix, $directory, $writeProtection));
    }

    public function list(): ReplicaList
    {
        return $this->client->call(Wire::listReplicas());
    }

    /** @param string $name */
    public function stop(mixed $name): void
    {
        $this->client->call(Wire::stopReplica($name));
    }

    /**
     * @param string $prefix
     * @param string|\Stringable|\SplFileInfo $directory
     */
    public function stopPrefix(mixed $prefix, mixed $directory = null): void
    {
        $this->client->call(Wire::stopPrefixReplica($prefix, $directory));
    }
}
