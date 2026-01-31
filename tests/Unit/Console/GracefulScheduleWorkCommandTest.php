<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeDispatchResult;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeSchedulingMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Orchestrator\DefaultScheduleOrchestrator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class GracefulScheduleWorkCommandTest extends TestCase
{
    /** @var string */
    private $tempDir;

    /** @var FakeEventMutex */
    private $eventMutex;

    /** @var FakeSchedulingMutex */
    private $schedulingMutex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/graceful-worker-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->eventMutex = new FakeEventMutex();
        $this->schedulingMutex = new FakeSchedulingMutex();
    }

    protected function tearDown(): void
    {
        $this->cleanupTempDir();
        Container::setInstance(null);
        parent::tearDown();
    }

    private function cleanupTempDir(): void
    {
        if (! is_dir($this->tempDir)) {
            return;
        }

        // Clean up files and subdirectories
        foreach (scandir($this->tempDir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $this->tempDir . '/' . $item;
            if (is_dir($path)) {
                chmod($path, 0755);
                rmdir($path);
            } else {
                chmod($path, 0644);
                unlink($path);
            }
        }

        rmdir($this->tempDir);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{int, string}
     */
    private function runCommand(array $options = []): array
    {
        $container = new Container();
        Container::setInstance($container);

        $container->instance(EventMutex::class, $this->eventMutex);
        $container->instance(SchedulingMutex::class, $this->schedulingMutex);

        $schedule = new Schedule();
        $fakeResult = FakeDispatchResult::success('test-id', 'echo test', 'fake');
        $dispatcher = new FakeDispatcher($fakeResult);
        $orchestrator = new DefaultScheduleOrchestrator($dispatcher);

        $command = new GracefulScheduleWorkCommand($orchestrator, $schedule);
        $command->setLaravel($container);

        $input = new ArrayInput($options, $command->getDefinition());
        $output = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        return [$exitCode, $output->fetch()];
    }

    /**
     * @testdox T4.10 Returns error when output directory does not exist
     */
    public function testReturnsErrorWhenOutputDirectoryDoesNotExist(): void
    {
        $nonExistentDir = $this->tempDir . '/non-existent/output.log';

        [$exitCode, $output] = $this->runCommand([
            '--run-output-file' => $nonExistentDir,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('The directory does not exist:', $output);
        $this->assertStringContainsString('non-existent', $output);
    }

    /**
     * @testdox T4.11 Returns error when output directory is not writable
     */
    public function testReturnsErrorWhenOutputDirectoryIsNotWritable(): void
    {
        $readOnlyDir = $this->tempDir . '/readonly';
        mkdir($readOnlyDir, 0555, true);

        $outputFile = $readOnlyDir . '/output.log';

        [$exitCode, $output] = $this->runCommand([
            '--run-output-file' => $outputFile,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('The directory is not writable:', $output);
        $this->assertStringContainsString('readonly', $output);
    }

    /**
     * @testdox T4.12 Returns error when output file is not writable
     */
    public function testReturnsErrorWhenOutputFileIsNotWritable(): void
    {
        $readOnlyFile = $this->tempDir . '/readonly.log';
        touch($readOnlyFile);
        chmod($readOnlyFile, 0444);

        [$exitCode, $output] = $this->runCommand([
            '--run-output-file' => $readOnlyFile,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('The output file is not writable:', $output);
        $this->assertStringContainsString('readonly.log', $output);
    }
}
