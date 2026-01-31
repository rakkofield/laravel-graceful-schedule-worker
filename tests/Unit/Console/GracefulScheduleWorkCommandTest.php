<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Console\GracefulScheduleWorkCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class GracefulScheduleWorkCommandTest extends TestCase
{
    /** @var string */
    private $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/graceful-worker-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->cleanupTempDir();
        Container::setInstance(null);
        parent::tearDown();
    }

    private function cleanupTempDir(): void
    {
        if (is_dir($this->tempDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $file) {
                if ($file->isDir()) {
                    chmod($file->getRealPath(), 0755);
                    rmdir($file->getRealPath());
                } else {
                    chmod($file->getRealPath(), 0644);
                    unlink($file->getRealPath());
                }
            }

            rmdir($this->tempDir);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array{int, string}
     */
    private function runCommand(array $options = []): array
    {
        $container = new Container();
        Container::setInstance($container);

        $command = new GracefulScheduleWorkCommand();
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
