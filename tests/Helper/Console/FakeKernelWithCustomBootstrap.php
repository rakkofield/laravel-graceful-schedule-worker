<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

/**
 * Fake Kernel that defines its own bootstrap(), as described in the README
 *
 * The kernel's bootstrap() replaces the one from UsesClockAwareSchedule.
 * It calls parent::bootstrap() and then registerClockAwareSchedule(), unless
 * $registerInBootstrap is false.
 *
 * Extends StubConsoleKernel so that parent::bootstrap() works.
 */
class FakeKernelWithCustomBootstrap extends StubConsoleKernel
{
    use UsesClockAwareSchedule;

    /** @var Container Stub for ConsoleKernel::$app */
    public $app;

    /** @var bool Whether the kernel's own bootstrap() has run */
    public $customBootstrapped = false;

    /** @var Event[] Events registered via gracefulSchedule() */
    public $gracefulScheduleEvents = [];

    /** @var bool Whether bootstrap() calls registerClockAwareSchedule() */
    private $registerInBootstrap;

    /**
     * @param bool $registerInBootstrap
     */
    public function __construct($registerInBootstrap = true)
    {
        $this->app = Container::getInstance();
        $this->registerInBootstrap = $registerInBootstrap;
    }

    /**
     * @return void
     */
    public function bootstrap()
    {
        parent::bootstrap();

        $this->customBootstrapped = true;

        if ($this->registerInBootstrap) {
            $this->registerClockAwareSchedule();
        }
    }

    protected function gracefulSchedule(ClockAwareSchedule $schedule)
    {
        $this->gracefulScheduleEvents[] = $schedule->command('clock-aware-task');
    }
}
