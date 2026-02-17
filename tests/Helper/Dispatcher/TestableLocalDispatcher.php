<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedLocalDispatchResult;

/**
 * Testable LocalDispatcher
 *
 * Extends LocalDispatcher to allow injecting processes externally.
 */
class TestableLocalDispatcher extends LocalDispatcher
{
    /**
     * @param StartedLocalDispatchResult $result
     * @return void
     */
    public function addRunningProcess(StartedLocalDispatchResult $result): void
    {
        $this->runningProcesses[] = $result;
    }
}
