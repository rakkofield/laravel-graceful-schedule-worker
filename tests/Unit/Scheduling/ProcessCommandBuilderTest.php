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
     * @testdox PCB.2 Unix: main command output goes to specified file, not /dev/null
     */
    public function testMainCommandOutputGoesToSpecifiedFile(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);
        $event->runInBackground = true;
        $event->appendOutputTo('/tmp/test.log');

        $command = $this->builder->buildCommand($event);
        $output = ProcessUtils::escapeArgument('/tmp/test.log');

        // Main command part (before "& CHILD") should redirect to specified file
        $mainPart = substr($command, 0, (int) strpos($command, '& CHILD'));
        $this->assertStringContainsString('>> ' . $output . ' 2>&1', $mainPart);
        // schedule:finish should also redirect to specified file
        $this->assertStringNotContainsString('> ' . ProcessUtils::escapeArgument('/dev/null'), $mainPart);
    }

    /**
     * @testdox PCB.3 Unix: command ends with schedule:finish redirect, no trailing &
     */
    public function testNoTrailingAmpersand(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);
        $event->runInBackground = true;

        $command = $this->builder->buildCommand($event);

        $this->assertStringEndsWith('2>&1', $command);
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
        $this->assertStringEndsWith('2>&1', $command);
    }

    /**
     * @testdox PCB.6 Unix: schedule:finish always uses append redirect even when sendOutputTo is used
     */
    public function testScheduleFinishUsesAppendRedirectWithSendOutputTo(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);
        $event->runInBackground = true;
        $event->sendOutputTo('/tmp/test.log');

        $command = $this->builder->buildCommand($event);
        $output = ProcessUtils::escapeArgument('/tmp/test.log');

        // Main command should use > (overwrite)
        $beforeFinish = substr($command, 0, (int) strpos($command, 'schedule:finish'));
        $this->assertStringContainsString('> ' . $output, $beforeFinish);

        // schedule:finish should always use >> (append) to avoid overwriting main command output
        $afterFinish = substr($command, (int) strpos($command, 'schedule:finish'));
        $this->assertStringContainsString('>> ' . $output . ' 2>&1', $afterFinish);
    }

    /**
     * @testdox PCB.5 Unix: wraps only main command with sudo -u when user is set
     */
    public function testWrapsWithSudoWhenUserIsSet(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);
        $event->runInBackground = true;
        $event->user = 'www-data';

        $command = $this->builder->buildCommand($event);

        // sudo wraps only the main command part (before & CHILD)
        $mainPart = substr($command, 0, (int) strpos($command, '& CHILD'));
        $this->assertStringContainsString('sudo -u www-data', $mainPart);

        // trap/wait/finish portion should NOT be wrapped in sudo
        $trapPart = substr($command, (int) strpos($command, '& CHILD'));
        $this->assertStringNotContainsString('sudo', $trapPart);
    }

    /**
     * @testdox PCB.7 Unix: uses trap pattern for SIGTERM forwarding
     */
    public function testUsesTrapPatternForSigtermForwarding(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);
        $event->runInBackground = true;
        $event->appendOutputTo('/tmp/test.log');

        $command = $this->builder->buildCommand($event);

        $this->assertStringContainsString('& CHILD=$!', $command);
        $this->assertStringContainsString("trap '", $command);
        $this->assertStringContainsString("' TERM", $command);
        $this->assertStringContainsString('kill $CHILD', $command);
        $this->assertStringContainsString('wait $CHILD', $command);
    }
}
