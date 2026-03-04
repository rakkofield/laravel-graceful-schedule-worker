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
     * @testdox PCB.2 Unix: main command uses exec prefix, output goes to specified file
     */
    public function testMainCommandUsesExecPrefix(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock, 'local', null, new TimezoneResolver());
        $event->runInBackground = true;
        $event->appendOutputTo('/tmp/test.log');

        $command = $this->builder->buildCommand($event);
        $output = ProcessUtils::escapeArgument('/tmp/test.log');

        // Uses exec prefix
        $this->assertStringStartsWith('exec ', $command);
        // Redirects to specified file
        $this->assertStringContainsString('>> ' . $output . ' 2>&1', $command);
        // Not /dev/null
        $this->assertStringNotContainsString(ProcessUtils::escapeArgument('/dev/null'), $command);
    }

    /**
     * @testdox PCB.3 Unix: command ends with 2>&1, no trailing &
     */
    public function testNoTrailingAmpersand(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock, 'local', null, new TimezoneResolver());
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
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock, 'local', null, new TimezoneResolver());
        $event->runInBackground = true;

        // buildCommand should NOT contain schedule:finish
        $command = $this->builder->buildCommand($event);
        $this->assertStringNotContainsString('schedule:finish', $command);
        $this->assertStringEndsWith('2>&1', $command);
    }

    /**
     * @testdox PCB.5 Unix: wraps with exec sudo -u when user is set
     */
    public function testWrapsWithSudoWhenUserIsSet(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock, 'local', null, new TimezoneResolver());
        $event->runInBackground = true;
        $event->user = 'www-data';

        $command = $this->builder->buildCommand($event);

        // Overall format: exec sudo -u www-data -- sh -c 'exec ...'
        $this->assertStringStartsWith('exec sudo -u www-data', $command);
        $this->assertStringContainsString("sh -c 'exec ", $command);
    }

    /**
     * @testdox PCB.6 Unix: sendOutputTo uses overwrite redirect
     */
    public function testSendOutputToUsesOverwriteRedirect(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock, 'local', null, new TimezoneResolver());
        $event->runInBackground = true;
        $event->sendOutputTo('/tmp/test.log');

        // Main command should use > (overwrite)
        $command = $this->builder->buildCommand($event);
        $output = ProcessUtils::escapeArgument('/tmp/test.log');
        $this->assertStringContainsString('> ' . $output, $command);
        $this->assertStringNotContainsString('>> ' . $output, $command);
    }

    /**
     * @testdox PCB.7 Unix: uses exec prefix instead of trap pattern
     */
    public function testUsesExecPrefixInsteadOfTrapPattern(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock, 'local', null, new TimezoneResolver());
        $event->runInBackground = true;
        $event->appendOutputTo('/tmp/test.log');

        $command = $this->builder->buildCommand($event);

        // Uses exec prefix
        $this->assertStringContainsString('exec', $command);
        // Does NOT use trap pattern
        $this->assertStringNotContainsString('trap', $command);
        $this->assertStringNotContainsString('& CHILD', $command);
    }

    /**
     * @testdox PCB.10 Unix: ensureCorrectUser adds exec inside sudo sh -c
     */
    public function testEnsureCorrectUserAddsExecInsideSudo(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock, 'local', null, new TimezoneResolver());
        $event->runInBackground = true;
        $event->user = 'www-data';

        $command = $this->builder->buildCommand($event);

        // exec inside sh -c for sudo
        $this->assertStringContainsString("sh -c 'exec php artisan test", $command);
    }
}
