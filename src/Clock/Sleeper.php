<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Clock;

use InvalidArgumentException;

class Sleeper implements SleeperInterface
{
    /** @var int */
    private $microseconds;

    /**
     * @param int $microseconds Sleep duration in microseconds (must be >= 1)
     * @throws InvalidArgumentException If $microseconds is 0 or less
     */
    public function __construct(int $microseconds)
    {
        if ($microseconds <= 0) {
            throw new InvalidArgumentException(
                "Sleep microseconds must be greater than 0, got {$microseconds}"
            );
        }

        $this->microseconds = $microseconds;
    }

    /**
     * {@inheritdoc}
     */
    public function sleep(): void
    {
        usleep($this->microseconds);
    }
}
