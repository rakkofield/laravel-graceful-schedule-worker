<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\E2E;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * E2E test that a task defined with withSchedule() in bootstrap/app.php is left
 * to schedule:run and is not run by the worker.
 *
 * On Laravel 13, withSchedule() registers its callback with
 * afterResolving(Schedule::class), which the container also runs for
 * ClockAwareSchedule. ClockAwareSchedule ignores the tasks it adds.
 *
 * Runs only on an application with the Laravel 11+ structure (skeleton-l11),
 * because withSchedule() is set up in its bootstrap/app.php.
 *
 * @group e2e
 */
final class WithScheduleE2ETest extends TestCase
{
    /**
     * @testdox E2E.17 A withSchedule() task is in Schedule but not in ClockAwareSchedule
     */
    public function testWithScheduleTaskIsNotRunByWorker(): void
    {
        // bootstrap/providers.php exists only in the Laravel 11+ structure
        if (!file_exists(SkeletonPath::get() . '/bootstrap/providers.php')) {
            $this->markTestSkipped('withSchedule() is set up only in the Laravel 11+ skeleton (skeleton-l11).');
        }

        $builder = SkeletonPath::get()
            . '/vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php';
        if (strpos((string) file_get_contents($builder), 'function withSchedule(') === false) {
            $this->markTestSkipped('withSchedule() exists since Laravel 11.1.');
        }

        $phpBinary = (new PhpExecutableFinder())->find(false);
        $process = new Process(
            [$phpBinary, 'artisan', 'e2e:list-schedules'],
            SkeletonPath::get(),
            ['E2E_WITH_SCHEDULE' => '1']
        );
        $process->mustRun();
        $output = $process->getOutput();

        // The task was registered, so the check below is not vacuous
        $this->assertStringContainsString('schedule: echo with-schedule-task', $output);
        $this->assertSame(1, preg_match('/^graceful: .* hello$/m', $output), 'gracefulSchedule() tasks are missing');
        $this->assertStringNotContainsString('graceful: echo with-schedule-task', $output);
    }
}
