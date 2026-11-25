<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

/**
 * how far a version must reach: server once the server holds it, cloud once the bucket holds it too
 */
final class Durability
{
    public const SERVER = 'server';
    public const CLOUD = 'cloud';

    /** Every value this SDK knows; a newer daemon may send others. */
    public const ALL = [
        self::SERVER,
        self::CLOUD,
    ];
}
