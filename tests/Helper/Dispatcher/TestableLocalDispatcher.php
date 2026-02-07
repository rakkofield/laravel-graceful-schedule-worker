<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\Result\StartedLocalDispatchResult;

/**
 * テスト用 LocalDispatcher
 *
 * プロセスを外部から注入可能にするために LocalDispatcher を継承します。
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
