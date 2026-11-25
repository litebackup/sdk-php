<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class Error
{
    public function __construct(
        public string $code,
        public string $message,
        /**
         * for rule_exists from backup-rules/start, the rule already saved for the path; stop it by
         * its ID before saving different settings
         */
        public ?BackupRule $rule,
    ) {}

    /** Decodes Error from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            code: WireModel::string($fields, 'code', true),
            message: WireModel::string($fields, 'message', true),
            rule: WireModel::model($fields, 'rule', BackupRule::fromWire(...), false),
        );
    }
}
