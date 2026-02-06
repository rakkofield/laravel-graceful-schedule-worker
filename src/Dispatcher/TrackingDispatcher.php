<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

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
 * - キャッシュ接続失敗時の fail-open/fail-close ハンドリング
 */
class TrackingDispatcher implements ScheduleDispatcherInterface
{
    public const FAIL_MODE_OPEN = 'open';
    public const FAIL_MODE_CLOSE = 'close';

    /** @var ScheduleDispatcherInterface */
    private $inner;

    /** @var ExecutionTrackerInterface */
    private $tracker;

    /** @var LoggerInterface */
    private $logger;

    /** @var string fail-open or fail-close */
    private $failMode;

    /**
     * @param ScheduleDispatcherInterface $inner 内部ディスパッチャー
     * @param ExecutionTrackerInterface $tracker 実行トラッカー
     * @param LoggerInterface $logger ロガー
     * @param string $failMode 'open' or 'close'（デフォルト: 'open'）
     */
    public function __construct(
        ScheduleDispatcherInterface $inner,
        ExecutionTrackerInterface $tracker,
        LoggerInterface $logger,
        string $failMode = self::FAIL_MODE_OPEN
    ) {
        $this->inner = $inner;
        $this->tracker = $tracker;
        $this->logger = $logger;
        $this->failMode = $failMode;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatchEvent(Event $event, Container $container, DateTimeInterface $dueAt): DispatchResultInterface
    {
        // 1. ロック取得（キャッシュ接続失敗時は fail_mode に応じて処理）
        try {
            $lockAcquired = $this->tracker->acquireLock($event, $dueAt);
        } catch (\Exception $e) {
            return $this->handleCacheFailure($event, $container, $dueAt, $e, 'acquireLock');
        }

        if (!$lockAcquired) {
            $this->logger->debug('[GracefulScheduleWorker] Lock not acquired, skipping dispatch', [
                'event' => $event->mutexName(),
                'dueAt' => $dueAt->format(\DateTimeInterface::ATOM),
            ]);

            return new SkippedDispatchResult(
                $event->mutexName(),
                (string) $event->command,
                'lock_not_acquired'
            );
        }

        // 2. 内部 Dispatcher に委譲
        $result = $this->inner->dispatchEvent($event, $container, $dueAt);

        // 3. 結果に応じたトラッキング（キャッシュ接続失敗時は fail_mode に応じて処理）
        try {
            $this->handleResult($result, $event, $dueAt);
        } catch (\LogicException $e) {
            // LogicException はプログラミングエラーなのでそのまま再スロー
            throw $e;
        } catch (\Exception $e) {
            // キャッシュ接続失敗時のハンドリング（markExecuted の失敗等）
            $this->logger->warning('[GracefulScheduleWorker] Failed to track execution result', [
                'event' => $event->mutexName(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            // markExecuted の失敗はディスパッチ自体には影響しないので、結果はそのまま返す
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
     * キャッシュ接続失敗時のハンドリング
     *
     * fail-open: ロック/トラッキングなしで実行を継続（重複実行の可能性あり）
     * fail-close: 例外を再スローしてワーカーを停止
     *
     * @param Event $event
     * @param Container $container
     * @param DateTimeInterface $dueAt
     * @param \Exception $exception
     * @param string $operation 失敗した操作名
     * @return DispatchResultInterface
     * @throws \RuntimeException fail-close モードの場合
     */
    private function handleCacheFailure(
        Event $event,
        Container $container,
        DateTimeInterface $dueAt,
        \Exception $exception,
        string $operation
    ): DispatchResultInterface {
        if ($this->failMode === self::FAIL_MODE_CLOSE) {
            $this->logger->error('[GracefulScheduleWorker] Cache connection failure in fail-close mode, stopping', [
                'event' => $event->mutexName(),
                'operation' => $operation,
                'error' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            throw new \RuntimeException(
                sprintf(
                    'Cache connection failure during %s for event %s: %s',
                    $operation,
                    $event->mutexName(),
                    $exception->getMessage()
                ),
                0,
                $exception
            );
        }

        // fail-open: ログ警告を出して実行を継続
        $message = '[GracefulScheduleWorker] Cache connection failure in fail-open mode, continuing without tracking';
        $this->logger->warning($message, [
            'event' => $event->mutexName(),
            'operation' => $operation,
            'error' => $exception->getMessage(),
            'exception' => $exception,
        ]);

        return $this->inner->dispatchEvent($event, $container, $dueAt);
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
