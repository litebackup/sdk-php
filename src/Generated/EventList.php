<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class EventList
{
    /**
     * @param list<Event> $events
     */
    public function __construct(
        public array $events,
        /** pass as after to continue */
        public int $next,
    ) {}

    /** Decodes EventList from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            events: WireModel::models($fields, 'events', Event::fromWire(...), true),
            next: WireModel::integer($fields, 'next', true),
        );
    }
}
