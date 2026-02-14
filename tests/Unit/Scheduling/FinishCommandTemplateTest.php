<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use PHPUnit\Framework\TestCase;

class FinishCommandTemplateTest extends TestCase
{
    /**
     * @testdox FCT.1 buildCommand(0) returns correct command with exit code 0
     */
    public function testBuildCommandWithExitCodeZero(): void
    {
        $template = new FinishCommandTemplate(
            "'/path/to/artisan' schedule:finish \"mutex-name\"",
            ">> '/path/to/output' 2>&1"
        );

        $command = $template->buildCommand(0);

        $this->assertSame(
            "'/path/to/artisan' schedule:finish \"mutex-name\" 0 >> '/path/to/output' 2>&1",
            $command
        );
    }

    /**
     * @testdox FCT.2 buildCommand(143) returns correct command with exit code 143
     */
    public function testBuildCommandWithExitCode143(): void
    {
        $template = new FinishCommandTemplate(
            "'/path/to/artisan' schedule:finish \"mutex-name\"",
            ">> '/path/to/output' 2>&1"
        );

        $command = $template->buildCommand(143);

        $this->assertSame(
            "'/path/to/artisan' schedule:finish \"mutex-name\" 143 >> '/path/to/output' 2>&1",
            $command
        );
    }

    /**
     * @testdox FCT.3 buildCommand handles output path containing percent sign without error
     */
    public function testBuildCommandWithPercentInOutputPath(): void
    {
        $template = new FinishCommandTemplate(
            "'/path/to/artisan' schedule:finish \"mutex-name\"",
            ">> '/path/to/100%done.log' 2>&1"
        );

        $command = $template->buildCommand(0);

        $this->assertSame(
            "'/path/to/artisan' schedule:finish \"mutex-name\" 0 >> '/path/to/100%done.log' 2>&1",
            $command
        );
    }
}
