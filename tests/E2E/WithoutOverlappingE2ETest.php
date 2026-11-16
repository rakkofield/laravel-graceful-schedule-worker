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
 * E2E.15 uses the skeleton's default file cache for the mutex, E2E.16 uses
 * Redis. E2E.16 runs only when E2E_REDIS is set (REDIS_HOST / REDIS_PORT
 * select the server), because each test takes up to about 200 seconds.
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

        // A mutex left in the file cache by an earlier run (e.g. one where
        // release was broken) would skip the tasks for up to 24 hours, so
        // remove the file cache entries first.
        $path = self::SKELETON_PATH . '/storage/framework/cache';
        (new Process(['find', $path, '-type', 'f', '!', '-name', '.gitignore', '-delete']))->run();
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
     * @param array<string, string> $env Extra environment variables for the worker
     * @return array{bg: int, fg: int}
     */
    private function runWorkerOverTwoMinutes(array $env = []): array
    {
        $phpBinary = (new PhpExecutableFinder())->find(false);
        $this->worker = new Process(
            [$phpBinary, 'artisan', 'schedule:graceful-work'],
            self::SKELETON_PATH,
            ['E2E_OVERLAP' => '1'] + $env
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
     * @param array{bg: int, fg: int} $counts
     */
    private function assertBothTasksRanTwice(array $counts): void
    {
        $this->assertGreaterThanOrEqual(
            2,
            $counts['bg'],
            'background (runInBackground) task did not run on the next minute'
        );
        $this->assertGreaterThanOrEqual(2, $counts['fg'], 'foreground task did not run on the next minute');
    }

    /**
     * Files under storage/framework/cache other than .gitignore.
     *
     * @return string[]
     */
    private static function fileCacheEntries(): array
    {
        $process = new Process(
            ['find', self::SKELETON_PATH . '/storage/framework/cache', '-type', 'f', '!', '-name', '.gitignore']
        );
        $process->run();

        return array_values(array_filter(explode("\n", $process->getOutput())));
    }

    /**
     * Both cases are checked in one worker run to keep the wait short.
     *
     * @testdox E2E.15 withoutOverlapping() tasks run again on the next minute (background and foreground)
     */
    public function testWithoutOverlappingTasksRunOnConsecutiveMinutes(): void
    {
        $this->assertBothTasksRanTwice($this->runWorkerOverTwoMinutes());
    }

    /**
     * Same as E2E.15 with the mutex in Redis (CacheEventMutex on the redis store).
     *
     * @testdox E2E.16 withoutOverlapping() tasks run again on the next minute with the Redis cache
     */
    public function testWithoutOverlappingTasksRunOnConsecutiveMinutesWithRedis(): void
    {
        if (!getenv('E2E_REDIS')) {
            $this->markTestSkipped('Set E2E_REDIS=1 (and REDIS_HOST / REDIS_PORT) to run against Redis.');
        }

        $counts = $this->runWorkerOverTwoMinutes([
            // Laravel 6-10 read CACHE_DRIVER, Laravel 11+ CACHE_STORE
            'CACHE_DRIVER' => 'redis',
            'CACHE_STORE' => 'redis',
            // A fresh prefix keeps a mutex left in Redis by an earlier run from skipping the tasks
            'CACHE_PREFIX' => 'e2e_' . bin2hex(random_bytes(4)) . '_',
        ]);

        // If the store had not switched, the mutex would have been written to the file cache
        $this->assertSame([], self::fileCacheEntries(), 'the mutex was not stored in Redis');
        $this->assertBothTasksRanTwice($counts);
    }
}
