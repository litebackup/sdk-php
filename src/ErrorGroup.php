<?php

declare(strict_types=1);

namespace Litebackup;

use RuntimeException;
use Throwable;

/**
 * Several errors raised together, the first one first, as a lease that
 * failed and then failed to clean up raises them. PHP has no exception
 * group, so this is the SDK's.
 */
final class ErrorGroup extends RuntimeException
{
    /**
     * @param list<Throwable> $errors
     */
    public function __construct(string $message, public readonly array $errors)
    {
        $messages = array_map(static fn(Throwable $error): string => $error->getMessage(), $errors);
        parent::__construct(
            $message . ' failed in ' . count($errors) . ' ways: ' . implode('; ', $messages),
            0,
            $errors[0] ?? null,
        );
    }

    /**
     * Throws one error alone, or several as an ErrorGroup, first one first;
     * returns when there are none.
     *
     * @param list<Throwable> $failures
     */
    public static function throwTogether(array $failures, string $message): void
    {
        if (count($failures) === 1) {
            throw $failures[0];
        }
        if ($failures !== []) {
            throw new self($message, $failures);
        }
    }
}
