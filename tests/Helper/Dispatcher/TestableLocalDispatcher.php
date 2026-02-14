<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedLocalDispatchResult;

/**
 * Testable LocalDispatcher
 *
 * Extends LocalDispatcher to allow injecting processes externally
 * and spying on runFinishCommand calls.
 */
class TestableLocalDispatcher extends LocalDispatcher
{
    /** @var array<string> */
    private $finishCommandsRun = [];

    /** @var array<int, \Throwable> */
    private $finishExceptions = [];

    /**
     * @param StartedLocalDispatchResult $result
     * @return void
     */
    public function addRunningProcess(StartedLocalDispatchResult $result): void
    {
        $this->runningProcesses[] = $result;
    }

    /**
     * Configure runFinishCommand to throw for a specific call index.
     *
     * @param int $index Zero-based index of the call that should throw
     * @param \Throwable $e
     * @return void
     */
    public function willThrowOnFinishCommand(int $index, \Throwable $e): void
    {
        $this->finishExceptions[$index] = $e;
    }

    /**
     * @param StartedLocalDispatchResult $result
     * @return void
     */
    protected function runFinishCommand(StartedLocalDispatchResult $result): void
    {
        $index = count($this->finishCommandsRun);
        $template = $result->getFinishCommandTemplate();
        if ($template !== null) {
            $exitCode = $result->getExitCode() ?? LocalDispatcher::EXIT_CODE_SIGTERM;
            $this->finishCommandsRun[] = $template->buildCommand($exitCode);
        }

        if (isset($this->finishExceptions[$index])) {
            throw $this->finishExceptions[$index];
        }
    }

    /**
     * @return array<string>
     */
    public function getFinishCommandsRun(): array
    {
        return $this->finishCommandsRun;
    }
}
