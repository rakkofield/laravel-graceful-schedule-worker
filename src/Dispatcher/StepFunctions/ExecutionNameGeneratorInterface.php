<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;

interface ExecutionNameGeneratorInterface
{
    /**
     * Generate an Execution Name from an Event and its scheduled due time.
     *
     * @param Event $event Schedule event
     * @param DateTimeInterface $dueAt Scheduled due time
     * @return string Execution Name (max 80 chars, allowed chars: a-z, A-Z, 0-9, -, _)
     */
    public function generate(Event $event, DateTimeInterface $dueAt): string;
}
