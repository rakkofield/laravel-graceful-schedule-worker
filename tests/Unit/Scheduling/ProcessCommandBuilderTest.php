<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use DateTimeImmutable;
use Illuminate\Support\ProcessUtils;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;

class ProcessCommandBuilderTest extends TestCase
{
    /**
     * @var FakeEventMutex
     */
    private $mutex;

    /**
     * @var ProcessCommandBuilder
     */
    private $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mutex = new FakeEventMutex();
        $this->builder = new ProcessCommandBuilder();
    }

    /**
     * @testdox PCB.1 Unix: schedule:finish redirects to the same output file as the main command
     */
    public function testScheduleFinishRedirectsToSameOutputFile(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);
        $event->runInBackground = true;
        $event->appendOutputTo('/tmp/test.log');

        $command = $this->builder->buildCommand($event);
        $output = ProcessUtils::escapeArgument('/tmp/test.log');

        $this->assertStringContainsString('schedule:finish', $command);
        $afterFinish = substr($command, (int) strpos($command, 'schedule:finish'));
        $this->assertStringContainsString('>> ' . $output . ' 2>&1', $afterFinish);
    }

    /**
     * @testdox PCB.2 Unix: no outer /dev/null redirect
     */
    public function testNoOuterDevNullRedirect(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);
        $event->runInBackground = true;
        $event->appendOutputTo('/tmp/test.log');

        $command = $this->builder->buildCommand($event);

        $this->assertStringNotContainsString('/dev/null', $command);
    }

    /**
     * @testdox PCB.3 Unix: no trailing &
     */
    public function testNoTrailingAmpersand(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);
        $event->runInBackground = true;

        $command = $this->builder->buildCommand($event);

        $this->assertStringEndsWith(')', $command);
    }

    /**
     * @testdox PCB.4 Unix: works correctly with default output (/dev/null)
     */
    public function testWorksWithDefaultOutput(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);
        $event->runInBackground = true;

        $command = $this->builder->buildCommand($event);
        $devNull = ProcessUtils::escapeArgument('/dev/null');

        $this->assertStringContainsString('schedule:finish', $command);
        $this->assertStringContainsString($devNull, $command);
        $this->assertStringEndsWith(')', $command);
    }

    /**
     * @testdox PCB.5 Unix: wraps with sudo -u when user is set
     */
    public function testWrapsWithSudoWhenUserIsSet(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);
        $event->runInBackground = true;
        $event->user = 'www-data';

        $command = $this->builder->buildCommand($event);

        $this->assertStringContainsString('sudo -u www-data', $command);
    }
}
