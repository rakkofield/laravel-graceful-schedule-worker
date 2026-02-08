<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Console;

use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

/**
 * UsesClockAwareSchedule trait のテスト用 Fake Kernel
 *
 * illuminate/foundation に依存せず trait の動作を検証するため、
 * 最小限のスタブ実装を持つ。
 */
class FakeKernelWithTrait
{
    use UsesClockAwareSchedule {
        defineConsoleSchedule as public;
    }

    /** @var ClockAwareSchedule|null gracefulSchedule() で受け取った schedule */
    public $receivedSchedule;

    /** @var \DateTimeZone|string|null scheduleTimezone() の戻り値 */
    private $timezone;

    /** @var string|null scheduleCache() の戻り値 */
    private $cacheStore;

    /** @var bool scheduleTimezone() を持つかどうか */
    private $hasTimezoneMethod = true;

    /** @var bool scheduleCache() を持つかどうか */
    private $hasCacheMethod = true;

    /**
     * @param \DateTimeZone|string|null $timezone
     * @param string|null $cacheStore
     */
    public function __construct($timezone = null, $cacheStore = null)
    {
        $this->timezone = $timezone;
        $this->cacheStore = $cacheStore;
    }

    protected function gracefulSchedule(ClockAwareSchedule $schedule)
    {
        $this->receivedSchedule = $schedule;
    }

    /**
     * @return \DateTimeZone|string|null
     */
    protected function scheduleTimezone()
    {
        return $this->timezone;
    }

    /**
     * @return string|null
     */
    protected function scheduleCache()
    {
        return $this->cacheStore;
    }
}
