<?php

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

/**
 * 複数のDispatcherを保持し、event.typeに応じて委譲する
 */
class CompositeDispatcher implements ScheduleDispatcherInterface
{
    /** @var array<string, ScheduleDispatcherInterface> */
    private $dispatchers;

    /** @var string DIで注入（config参照はServiceProviderのみ） */
    private $defaultType;

    /**
     * @param array<string, ScheduleDispatcherInterface> $dispatchers
     * @param string $defaultType
     */
    public function __construct(array $dispatchers, string $defaultType)
    {
        $this->dispatchers = $dispatchers;
        $this->defaultType = $defaultType;
    }

    /**
     * 単一イベントをディスパッチする
     *
     * @param Event $event 実行するスケジュールイベント
     * @param Container $container Laravel コンテナインスタンス
     * @return bool 実行が成功したかどうか
     */
    public function dispatchEvent(Event $event, Container $container): bool
    {
        $type = $this->resolveDispatcherType($event);

        if (!isset($this->dispatchers[$type])) {
            throw new \InvalidArgumentException("Unknown dispatcher type: {$type}");
        }

        return $this->dispatchers[$type]->dispatchEvent($event, $container);
    }

    /**
     * イベントから使用するDispatcherタイプを解決する
     *
     * @param Event $event
     * @return string
     */
    private function resolveDispatcherType(Event $event): string
    {
        if ($event instanceof ClockAwareEvent && $event->getDispatcherType() !== null) {
            return $event->getDispatcherType();
        }
        return $this->defaultType;
    }
}
