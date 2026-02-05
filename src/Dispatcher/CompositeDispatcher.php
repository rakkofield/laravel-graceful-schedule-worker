<?php

declare(strict_types=1);

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
     * @throws \InvalidArgumentException dispatchers が空または defaultType が存在しない場合
     */
    public function __construct(array $dispatchers, string $defaultType)
    {
        if (empty($dispatchers)) {
            throw new \InvalidArgumentException('Dispatchers array cannot be empty');
        }

        if (!isset($dispatchers[$defaultType])) {
            $availableTypes = implode(', ', array_keys($dispatchers));
            throw new \InvalidArgumentException(
                "Default dispatcher type '{$defaultType}' not found in dispatchers. Available types: {$availableTypes}"
            );
        }

        $this->dispatchers = $dispatchers;
        $this->defaultType = $defaultType;
    }

    /**
     * 単一イベントをディスパッチする
     *
     * @param Event $event 実行するスケジュールイベント
     * @param Container $container Laravel コンテナインスタンス
     * @return DispatchResultInterface ディスパッチ結果
     */
    public function dispatchEvent(Event $event, Container $container): DispatchResultInterface
    {
        $type = $this->resolveDispatcherType($event);

        if (!isset($this->dispatchers[$type])) {
            $availableTypes = implode(', ', array_keys($this->dispatchers));
            throw new \InvalidArgumentException(
                "Unknown dispatcher type: {$type}. Available types: {$availableTypes}"
            );
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

    /**
     * {@inheritdoc}
     */
    public function cleanup(): void
    {
        foreach ($this->dispatchers as $dispatcher) {
            $dispatcher->cleanup();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stopAll(): void
    {
        foreach ($this->dispatchers as $dispatcher) {
            $dispatcher->stopAll();
        }
    }
}
