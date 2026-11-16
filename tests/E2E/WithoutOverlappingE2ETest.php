<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\E2E;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * E2E tests that a withoutOverlapping() task runs again on the next minute.
 *
 * The worker must release the overlap mutex after each run. If it does not,
 * the task runs once and is then skipped until the mutex expires (24 hours
 * by default).
 *
 * The skeleton registers the tasks only when E2E_OVERLAP is set.
 * The test needs two minute boundaries. It waits up to three, so that one
 * missed minute (e.g. a loaded CI runner) does not fail it, and takes up to
 * about 200 seconds.
 *
 * @group e2e
 * @group slow
 */
final class WithoutOverlappingE2ETest extends TestCase
{
    private const SKELETON_PATH = __DIR__ . '/../../skeleton';
    private const MAX_WAIT_SECONDS = 200;
    private const POLL_INTERVAL_MICROSECONDS = 500000; // 500ms

    /** @var Process|null */
    private $worker;

    private static function logPath(string $name): string
    {
        return self::SKELETON_PATH . '/storage/logs/' . $name;
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['e2e_overlap_bg.log', 'e2e_overlap_fg.log'] as $name) {
            @unlink(self::logPath($name));
        }

        // A mutex left by an earlier run (e.g. one where release was broken)
        // would skip the tasks for up to 24 hours, so remove it first.
        // Laravel 11+ file cache keeps locks in a separate directory
        // (framework/cache/locks) that cache:clear does not touch.
        foreach (['data', 'locks'] as $dir) {
            $path = self::SKELETON_PATH . '/storage/framework/cache/' . $dir;
            (new Process(['find', $path, '-type', 'f', '!', '-name', '.gitignore', '-delete']))->run();
        }
    }

    protected function tearDown(): void
    {
        if ($this->worker !== null && $this->worker->isRunning()) {
            $this->worker->signal(SIGTERM);
            $this->worker->wait();
        }
        parent::tearDown();
    }

    private function runCount(string $name): int
    {
        $path = self::logPath($name);
        if (!file_exists($path)) {
            return 0;
        }

        return count(array_filter(explode("\n", (string) file_get_contents($path))));
    }

    /**
     * Start the worker and wait until both overlap tasks have run twice or the time runs out.
     *
     * @return array{bg: int, fg: int}
     */
    private function runWorkerOverTwoMinutes(): array
    {
        $phpBinary = (new PhpExecutableFinder())->find(false);
        $this->worker = new Process(
            [$phpBinary, 'artisan', 'schedule:graceful-work'],
            self::SKELETON_PATH,
            ['E2E_OVERLAP' => '1']
        );
        $this->worker->setTimeout(null);
        $this->worker->start();

        $startTime = time();
        while (time() - $startTime < self::MAX_WAIT_SECONDS) {
            if ($this->runCount('e2e_overlap_bg.log') >= 2 && $this->runCount('e2e_overlap_fg.log') >= 2) {
                break;
            }
            if (!$this->worker->isRunning()) {
                $this->fail("Worker exited early.\n" . $this->worker->getOutput() . $this->worker->getErrorOutput());
            }
            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        $this->worker->signal(SIGTERM);
        $this->worker->wait();

        return [
            'bg' => $this->runCount('e2e_overlap_bg.log'),
            'fg' => $this->runCount('e2e_overlap_fg.log'),
        ];
    }

    /**
     * Both cases are checked in one worker run to keep the wait short.
     *
     * @testdox E2E.15 withoutOverlapping() tasks run again on the next minute (background and foreground)
     */
    public function testWithoutOverlappingTasksRunOnConsecutiveMinutes(): void
    {
        $counts = $this->runWorkerOverTwoMinutes();

        $this->assertGreaterThanOrEqual(
            2,
            $counts['bg'],
            'background (runInBackground) task did not run on the next minute'
        );
        $this->assertGreaterThanOrEqual(2, $counts['fg'], 'foreground task did not run on the next minute');
    }
}
