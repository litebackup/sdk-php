<?php

declare(strict_types=1);

namespace Litebackup;

use Closure;
use Generator;
use Litebackup\Generated\Event;
use Litebackup\Generated\EventList;

/** The daemon's event log. */
final class Events extends Group
{
    /**
     * One page after an event ID; -1 returns none and, as next, where the log ends.
     *
     * @param int $after
     * @param int|float|null $wait seconds the daemon waits for an event
     * @param int|null $limit
     */
    public function page(mixed $after = 0, mixed $wait = null, mixed $limit = null): EventList
    {
        return $this->client->call(Wire::eventsPage($after, $wait, $limit));
    }

    /**
     * Yields events, oldest first, by long-polling until the caller stops
     * iterating. A failed long-poll is retried with backoff from the same
     * cursor, and onError hears each failure; sdk_unsupported, closed,
     * cancelled, invalid_argument, and cursor_expired (the cursor is older
     * than the oldest event kept; choose a new one) end the watch by
     * throwing.
     *
     * @param int $after
     * @param int|float|null $wait seconds each long-poll waits; 30 by default
     * @param (Closure(LitebackupError): void)|null $onError
     * @return Generator<int, Event, mixed, void>
     */
    public function watch(mixed $after = 0, mixed $wait = null, ?Closure $onError = null): Generator
    {
        $cursor = Wire::optionalInteger($after, 'after') ?? 0;
        $waitSeconds = Wire::seconds($wait, 'the event wait') ?? Wire::EVENT_WAIT_SECONDS;
        return $this->watchFrom($cursor, $waitSeconds, $onError);
    }

    /**
     * @param (Closure(LitebackupError): void)|null $onError
     * @return Generator<int, Event, mixed, void>
     */
    private function watchFrom(int $cursor, float $waitSeconds, ?Closure $onError): Generator
    {
        $delay = Wire::FIRST_RETRY_SECONDS;
        while (true) {
            try {
                $listing = $this->page($cursor, $waitSeconds);
            } catch (LitebackupError $error) {
                if (Wire::terminalWatchError($error)) {
                    throw $error;
                }
                if ($onError !== null) {
                    $onError($error);
                }
                Clock::sleep($delay);
                $delay = min($delay * 2, Wire::MAXIMUM_RETRY_SECONDS);
                continue;
            }
            $delay = Wire::FIRST_RETRY_SECONDS;
            foreach ($listing->events as $event) {
                $cursor = max($cursor, $event->id);
                yield $event;
            }
            $cursor = max($cursor, $listing->next);
        }
    }
}
