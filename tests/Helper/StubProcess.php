<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Helper;

use Symfony\Component\Process\Process;

/**
 * テスト用の Process スタブ
 *
 * Symfony Process を継承し、プロセスの状態をスタブ化します。
 */
class StubProcess extends Process
{
    /** @var bool */
    private $running = false;

    /** @var bool */
    private $stopped = false;

    /** @var int|null */
    private $exitCode = null;

    /**
     * @param bool $running 初期状態での running フラグ
     */
    public function __construct(bool $running = true)
    {
        parent::__construct(['echo', 'stub']);
        $this->running = $running;
    }

    /**
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->running && !$this->stopped;
    }

    /**
     * @param int $timeout
     * @param int|null $signal
     * @return int|null
     */
    public function stop(float $timeout = 10, int $signal = null): ?int
    {
        $this->stopped = true;
        $this->running = false;
        $this->exitCode = 143; // SIGTERM
        return $this->exitCode;
    }

    /**
     * @return int|null
     */
    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * Test helper: set whether the process is running.
     *
     * @param bool $running
     * @return void
     */
    public function setRunning(bool $running): void
    {
        $this->running = $running;
    }

    /**
     * Test helper: check if stop() was called.
     *
     * @return bool
     */
    public function wasStopped(): bool
    {
        return $this->stopped;
    }
}
