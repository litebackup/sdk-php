<?php

declare(strict_types=1);

namespace Litebackup\Tests\Support;

use Litebackup\Adapter;
use RuntimeException;
use stdClass;

/** An adapter that saves each step with its monotonic time and fails open or close on demand. */
final class StepAdapter implements Adapter
{
    /** @var list<array{string, float}> */
    public array $steps = [];
    public ?string $openPath = null;
    public readonly RuntimeException $openError;
    public readonly RuntimeException $closeError;

    /** @param list<string> $failing */
    public function __construct(private readonly array $failing)
    {
        $this->openError = new RuntimeException('open failed');
        $this->closeError = new RuntimeException('close failed');
    }

    public function step(string $step): void
    {
        $this->steps[] = [$step, hrtime(true) / 1e9];
    }

    public function open(string $path): object
    {
        $this->step('open');
        $this->openPath = $path;
        if (in_array('open', $this->failing, true)) {
            throw $this->openError;
        }
        return new stdClass();
    }

    public function close(object $handle): void
    {
        $this->step('close');
        if (in_array('close', $this->failing, true)) {
            throw $this->closeError;
        }
    }
}
