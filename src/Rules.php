<?php

declare(strict_types=1);

namespace Litebackup;

use Litebackup\Generated\BackupRule;
use Litebackup\Generated\StopBackupResult;

/** Saved backup and failover rules. */
final class Rules extends Group
{
    /** @return list<BackupRule> */
    public function list(): array
    {
        return $this->client->call(Wire::listRules());
    }

    /**
     * Stops a whole rule, including future matches.
     *
     * @param string $ruleID
     */
    public function stop(mixed $ruleID): StopBackupResult
    {
        return $this->client->call(Wire::stopRule($ruleID));
    }
}
