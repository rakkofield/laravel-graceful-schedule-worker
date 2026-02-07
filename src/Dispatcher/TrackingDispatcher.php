<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use Psr\Log\LoggerInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\ExecutionTrackerInterface;

/**
 * トラッキング機能を追加するデコレーター
 *
 * 内部 Dispatcher をラップし、以下の責務を担う:
 * - ロック取得（重複実行防止）
 * - 実行記録（markExecuted）
 * - 失敗時のログ出力
 */
class TrackingDispatcher implements ScheduleDispatcherInterface
{
    /** @var ScheduleDispatcherInterface */
    private $inner;

    /** @var ExecutionTrackerInterface */
    private $tracker;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param ScheduleDispatcherInterface $inner 内部ディスパッチャー
     * @param ExecutionTrackerInterface $tracker 実行トラッカー
     * @param LoggerInterface $logger ロガー
     */
    public function __construct(
        ScheduleDispatcherInterface $inner,
        ExecutionTrackerInterface $tracker,
        LoggerInterface $logger
    ) {
        $this->inner = $inner;
        $this->tracker = $tracker;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatchEvent(Event $event, Container $container, DateTimeInterface $dueAt): DispatchResultInterface
    {
        // 1. ロック取得（キャッシュ接続失敗時は例外が伝播しワーカーが停止する）
        $lockAcquired = $this->tracker->acquireLock($event, $dueAt);

        if (!$lockAcquired) {
            $this->logger->debug('[GracefulScheduleWorker] Lock not acquired, skipping dispatch', [
                'event' => $event->mutexName(),
                'dueAt' => $dueAt->format(\DateTimeInterface::ATOM),
            ]);

            return new SkippedDispatchResult(
                $event->mutexName(),
                (string) $event->command,
                'lock_not_acquired',
                new DateTimeImmutable()
            );
        }

        // 2. 内部 Dispatcher に委譲
        $result = $this->inner->dispatchEvent($event, $container, $dueAt);

        // 3. 結果に応じたトラッキング
        try {
            $this->handleResult($result, $event, $dueAt);
        } catch (\LogicException $e) {
            // LogicException はプログラミングエラーなのでそのまま再スロー
            throw $e;
        } catch (\Exception $e) {
            // markExecuted の失敗はディスパッチ自体には影響しないので warning で継続
            $this->logger->warning('[GracefulScheduleWorker] Failed to track execution result', [
                'event' => $event->mutexName(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup(): void
    {
        $this->inner->cleanup();
    }

    /**
     * {@inheritdoc}
     */
    public function stopAll(): void
    {
        $this->inner->stopAll();
    }

    /**
     * ディスパッチ結果を処理
     *
     * Note: SkippedDispatchResultInterface は acquireLock 失敗時にのみ生成され、
     * inner dispatcher からは返されないため、ここでの処理は不要。
     * 新しい DispatchResultInterface サブタイプを追加する場合は、
     * このメソッドへの対応も必要。
     *
     * @param DispatchResultInterface $result
     * @param Event $event
     * @param DateTimeInterface $dueAt
     * @return void
     */
    private function handleResult(
        DispatchResultInterface $result,
        Event $event,
        DateTimeInterface $dueAt
    ): void {
        if ($result instanceof StartedDispatchResultInterface) {
            $this->tracker->markExecuted($event, $dueAt);
            return;
        }

        if ($result instanceof AlreadyRunningDispatchResultInterface) {
            // 既存実行は成功として扱い、実行済みとしてマーク
            $this->tracker->markExecuted($event, $dueAt);
            return;
        }

        if ($result instanceof FailedDispatchResultInterface) {
            $this->handleDispatchFailure($event, $result);
            return;
        }

        // 予期しない結果型 - これはバグを示す
        throw new \LogicException(sprintf(
            'Unexpected dispatch result type: %s (dispatcher: %s, event: %s)',
            get_class($result),
            $result->getDispatcherType(),
            $event->mutexName()
        ));
    }

    /**
     * ディスパッチ失敗時のハンドリング
     *
     * @param Event $event
     * @param FailedDispatchResultInterface $result
     * @return void
     */
    private function handleDispatchFailure(
        Event $event,
        FailedDispatchResultInterface $result
    ): void {
        $context = [
            'event' => $event->mutexName(),
            'dispatcher_type' => $result->getDispatcherType(),
            'error' => $result->getError(),
        ];

        $exception = $result->getException();
        if ($exception !== null) {
            $context['exception'] = $exception;
        }

        $this->logger->error('[GracefulScheduleWorker] Failed to dispatch event', $context);
    }
}
