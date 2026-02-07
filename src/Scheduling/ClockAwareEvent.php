<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;

class ClockAwareEvent extends Event
{
    /**
     * @var ClockInterface
     */
    protected $clock;

    /**
     * @var DateInterval|null
     */
    protected $gracePeriod = null;

    /**
     * @var bool
     */
    protected $recoverable = false;

    /**
     * @var string|null
     */
    protected $dispatcherType = null;

    /**
     * @param EventMutex $mutex
     * @param string $command
     * @param ClockInterface $clock
     * @param \DateTimeZone|string|null $timezone
     */
    public function __construct(EventMutex $mutex, $command, ClockInterface $clock, $timezone = null)
    {
        parent::__construct($mutex, $command, $timezone);
        $this->clock = $clock;
    }

    /**
     * リカバリを有効化（猶予期間を設定）
     *
     * @param int|null $minutes 猶予期間（分）。nullの場合は無制限
     * @return $this
     */
    public function withGracePeriod($minutes = null)
    {
        $this->recoverable = true;

        if ($minutes !== null && $minutes > 0) {
            $this->gracePeriod = new DateInterval("PT{$minutes}M");
        } else {
            $this->gracePeriod = null;
        }

        return $this;
    }

    /**
     * リカバリを有効化（猶予期間なし）
     *
     * @return $this
     */
    public function enableRecovery()
    {
        $this->recoverable = true;
        $this->gracePeriod = null;
        return $this;
    }

    /**
     * Dispatcher タイプを指定
     *
     * @param string $type 'local' または 'stepfunctions'
     * @return $this
     */
    public function dispatchVia($type)
    {
        $this->dispatcherType = $type;
        return $this;
    }

    /**
     * 指定されたDispatcherタイプを取得
     *
     * @return string|null Dispatcherタイプ（未指定の場合はnull）
     */
    public function getDispatcherType()
    {
        return $this->dispatcherType;
    }

    /**
     * 現在時刻を取得
     *
     * @return DateTimeImmutable
     */
    public function getCurrentTime()
    {
        return $this->clock->now();
    }

    /**
     * リカバリが有効かどうかを取得
     *
     * @return bool
     */
    public function isRecoverable()
    {
        return $this->recoverable;
    }

    /**
     * 猶予期間を取得
     *
     * @return DateInterval|null
     */
    public function getGracePeriod()
    {
        return $this->gracePeriod;
    }

    /**
     * Symfony Process で実行するためのコマンド文字列を構築する
     *
     * buildCommand() が付与する末尾の & を除去して返す。
     * Process::start() が非同期実行を提供するため & は不要であり、
     * & があると proc_terminate 時にプロセスグループ全体に SIGTERM が伝播する。
     *
     * runInBackground = true の状態で呼ぶことを前提とする。
     *
     * @return string
     */
    public function buildProcessCommand()
    {
        $command = $this->buildCommand();
        return preg_replace('/\s+&\s*$/', '', $command) ?? $command;
    }
}
