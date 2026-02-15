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
     * @testdox PCB.1 Unix: schedule:finish is returned by buildFinishCommand, not buildCommand
     */
    public function testScheduleFinishReturnedByBuildFinishCommand(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock, 'local');
        $event->runInBackground = true;
        $event->appendOutputTo('/tmp/test.log');

        // schedule:finish is NOT in buildCommand (exec approach)
        $command = $this->builder->buildCommand($event);
        $this->assertStringNotContainsString('schedule:finish', $command);

        // schedule:finish IS in buildFinishCommand
        $template = $this->builder->buildFinishCommand($event);
        $finishCommand = $template->buildCommand(0);
        $output = ProcessUtils::escapeArgument('/tmp/test.log');
        $this->assertStringContainsString('schedule:finish', $finishCommand);
        $this->assertStringContainsString('>> ' . $output . ' 2>&1', $finishCommand);
    }

    /**
     * @testdox PCB.2 Unix: main command uses exec prefix, output goes to specified file
     */
    public function testMainCommandUsesExecPrefix(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock, 'local');
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
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock, 'local');
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
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock, 'local');
        $event->runInBackground = true;

        // buildCommand should NOT contain schedule:finish
        $command = $this->builder->buildCommand($event);
        $this->assertStringNotContainsString('schedule:finish', $command);
        $this->assertStringEndsWith('2>&1', $command);

        // buildFinishCommand should contain schedule:finish
        $template = $this->builder->buildFinishCommand($event);
        $finishCommand = $template->buildCommand(0);
        $devNull = ProcessUtils::escapeArgument('/dev/null');
        $this->assertStringContainsString('schedule:finish', $finishCommand);
        $this->assertStringContainsString($devNull, $finishCommand);
        $this->assertStringEndsWith('2>&1', $finishCommand);
    }

    /**
     * @testdox PCB.5 Unix: wraps with exec sudo -u when user is set
     */
    public function testWrapsWithSudoWhenUserIsSet(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock, 'local');
        $event->runInBackground = true;
        $event->user = 'www-data';

        $command = $this->builder->buildCommand($event);

        // Overall format: exec sudo -u www-data -- sh -c 'exec ...'
        $this->assertStringStartsWith('exec sudo -u www-data', $command);
        $this->assertStringContainsString("sh -c 'exec ", $command);
    }

    /**
     * @testdox PCB.6 Unix: buildFinishCommand always uses append redirect even when sendOutputTo is used
     */
    public function testBuildFinishCommandUsesAppendRedirectWithSendOutputTo(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock, 'local');
        $event->runInBackground = true;
        $event->sendOutputTo('/tmp/test.log');

        // Main command should use > (overwrite)
        $command = $this->builder->buildCommand($event);
        $output = ProcessUtils::escapeArgument('/tmp/test.log');
        $this->assertStringContainsString('> ' . $output, $command);
        $this->assertStringNotContainsString('>> ' . $output, $command);

        // buildFinishCommand should always use >> (append) to avoid overwriting main command output
        $template = $this->builder->buildFinishCommand($event);
        $finishCommand = $template->buildCommand(0);
        $this->assertStringContainsString('>> ' . $output . ' 2>&1', $finishCommand);
    }

    /**
     * @testdox PCB.7 Unix: uses exec prefix instead of trap pattern
     */
    public function testUsesExecPrefixInsteadOfTrapPattern(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock, 'local');
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
     * @testdox PCB.8 Unix: buildFinishCommand returns FinishCommandTemplate with correct schedule:finish command
     */
    public function testBuildFinishCommandReturnsFinishCommandTemplate(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock, 'local');
        $event->runInBackground = true;

        $template = $this->builder->buildFinishCommand($event);

        $this->assertInstanceOf(FinishCommandTemplate::class, $template);
        $command = $template->buildCommand(0);
        $this->assertStringContainsString('schedule:finish', $command);
        $this->assertStringContainsString($event->mutexName(), $command);
    }

    /**
     * @testdox PCB.9 Unix: buildFinishCommand output redirect is always append
     */
    public function testBuildFinishCommandAlwaysUsesAppendRedirect(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));

        // With sendOutputTo (overwrite mode)
        $event1 = new ClockAwareEvent($this->mutex, 'php artisan test', $clock, 'local');
        $event1->runInBackground = true;
        $event1->sendOutputTo('/tmp/test.log');

        $template1 = $this->builder->buildFinishCommand($event1);
        $command1 = $template1->buildCommand(0);
        $output1 = ProcessUtils::escapeArgument('/tmp/test.log');
        $this->assertStringContainsString('>> ' . $output1, $command1);

        // With appendOutputTo (append mode)
        $event2 = new ClockAwareEvent($this->mutex, 'php artisan test2', $clock, 'local');
        $event2->runInBackground = true;
        $event2->appendOutputTo('/tmp/test2.log');

        $template2 = $this->builder->buildFinishCommand($event2);
        $command2 = $template2->buildCommand(0);
        $output2 = ProcessUtils::escapeArgument('/tmp/test2.log');
        $this->assertStringContainsString('>> ' . $output2, $command2);
    }

    /**
     * @testdox PCB.10 Unix: ensureCorrectUser adds exec inside sudo sh -c
     */
    public function testEnsureCorrectUserAddsExecInsideSudo(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock, 'local');
        $event->runInBackground = true;
        $event->user = 'www-data';

        $command = $this->builder->buildCommand($event);

        // exec inside sh -c for sudo
        $this->assertStringContainsString("sh -c 'exec php artisan test", $command);
    }

    /**
     * @testdox PCB.11 Unix: buildFinishCommand handles output path containing percent sign
     */
    public function testBuildFinishCommandHandlesPercentInOutputPath(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2024-01-01 12:00:00'));
        $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock, 'local');
        $event->runInBackground = true;
        $event->appendOutputTo('/tmp/100%done.log');

        $template = $this->builder->buildFinishCommand($event);
        $command = $template->buildCommand(0);

        $this->assertStringContainsString('schedule:finish', $command);
        $this->assertStringContainsString('100%done', $command);
        $this->assertStringContainsString(' 0 ', $command);
    }
}
