<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use Illuminate\Console\Command;

class StubCommand extends Command
{
    /** @var string */
    protected $name = 'stub:command';

    /** @var string */
    protected $description = 'Stub command for testing';
}
