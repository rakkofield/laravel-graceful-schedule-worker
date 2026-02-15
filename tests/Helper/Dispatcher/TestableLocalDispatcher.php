<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Illuminate\Contracts\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedLocalDispatchResult;

/**
 * Testable LocalDispatcher
 *
 * Extends LocalDispatcher to allow injecting processes and container externally.
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

    /**
     * Set the container for cleanup/stopAll usage.
     *
     * @param Container $container
     * @return void
     */
    public function setContainer(Container $container): void
    {
        $this->container = $container;
    }
}
