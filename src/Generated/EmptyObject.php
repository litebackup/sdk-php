<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class EmptyObject
{
    public function __construct() {}

    /** Decodes EmptyObject from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        WireModel::fields($value, self::class);
        return new self();
    }
}
