<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;

/**
 * スキップされたディスパッチ結果クラス
 *
 * ロック取得失敗など、ディスパッチがスキップされた場合に使用します。
 */
class SkippedDispatchResult implements SkippedDispatchResultInterface
{
    /** @var string */
    private $eventIdentifier;

    /** @var string */
    private $eventCommand;

    /** @var string */
    private $reason;

    /** @var DateTimeImmutable */
    private $dispatchedAt;

    /**
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param string $reason スキップ理由
     * @param DateTimeImmutable|null $dispatchedAt
     */
    public function __construct(
        string $eventIdentifier,
        string $eventCommand,
        string $reason,
        ?DateTimeImmutable $dispatchedAt = null
    ) {
        $this->eventIdentifier = $eventIdentifier;
        $this->eventCommand = $eventCommand;
        $this->reason = $reason;
        $this->dispatchedAt = $dispatchedAt ?? new DateTimeImmutable();
    }

    /**
     * {@inheritdoc}
     */
    public function getEventIdentifier(): string
    {
        return $this->eventIdentifier;
    }

    /**
     * {@inheritdoc}
     */
    public function getEventCommand(): string
    {
        return $this->eventCommand;
    }

    /**
     * Dispatcher 種別を取得
     *
     * SkippedDispatchResult は TrackingDispatcher 専用のため、
     * 常に 'tracking' を返す。
     *
     * @return string
     */
    public function getDispatcherType(): string
    {
        return 'tracking';
    }

    /**
     * {@inheritdoc}
     */
    public function getDispatchedAt(): DateTimeImmutable
    {
        return $this->dispatchedAt;
    }

    /**
     * {@inheritdoc}
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
