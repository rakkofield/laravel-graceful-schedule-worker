<?php

namespace RakkoInc\LaravelGracefulScheduleWorker\Tests\Unit\Dispatcher;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\CompositeDispatcher;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\ScheduleDispatcherInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareEvent;

class CompositeDispatcherTest extends TestCase
{
    /**
     * @var Container
     */
    private $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Container();
    }

    /**
     * T2.5: CompositeDispatcher_dispatchEvent_delegates_to_event_specified_dispatcher
     *
     * @test
     */
    public function it_delegates_to_event_specified_dispatcher()
    {
        $localDispatcher = $this->createMock(ScheduleDispatcherInterface::class);
        $sfnDispatcher = $this->createMock(ScheduleDispatcherInterface::class);

        $dispatcher = new CompositeDispatcher(
            [
                'local' => $localDispatcher,
                'stepfunctions' => $sfnDispatcher,
            ],
            'local'
        );

        $event = $this->createMock(ClockAwareEvent::class);
        $event->method('getDispatcherType')->willReturn('stepfunctions');

        // stepfunctions dispatcher が呼ばれることを確認
        $sfnDispatcher->expects($this->once())
            ->method('dispatchEvent')
            ->with($event, $this->app)
            ->willReturn(true);

        $localDispatcher->expects($this->never())
            ->method('dispatchEvent');

        $result = $dispatcher->dispatchEvent($event, $this->app);
        $this->assertTrue($result);
    }

    /**
     * T2.6: CompositeDispatcher_dispatchEvent_uses_default_when_no_event_setting
     *
     * @test
     */
    public function it_uses_default_when_no_event_setting()
    {
        $localDispatcher = $this->createMock(ScheduleDispatcherInterface::class);
        $sfnDispatcher = $this->createMock(ScheduleDispatcherInterface::class);

        $dispatcher = new CompositeDispatcher(
            [
                'local' => $localDispatcher,
                'stepfunctions' => $sfnDispatcher,
            ],
            'local'
        );

        // ClockAwareEvent でない Event（getDispatcherType がない）
        $event = $this->createMock(Event::class);

        // local dispatcher（default）が呼ばれることを確認
        $localDispatcher->expects($this->once())
            ->method('dispatchEvent')
            ->with($event, $this->app)
            ->willReturn(true);

        $sfnDispatcher->expects($this->never())
            ->method('dispatchEvent');

        $result = $dispatcher->dispatchEvent($event, $this->app);
        $this->assertTrue($result);
    }

    /**
     * T2.6補足: ClockAwareEvent で getDispatcherType() が null を返す場合
     *
     * @test
     */
    public function it_uses_default_when_dispatcher_type_is_null()
    {
        $localDispatcher = $this->createMock(ScheduleDispatcherInterface::class);
        $sfnDispatcher = $this->createMock(ScheduleDispatcherInterface::class);

        $dispatcher = new CompositeDispatcher(
            [
                'local' => $localDispatcher,
                'stepfunctions' => $sfnDispatcher,
            ],
            'local'
        );

        $event = $this->createMock(ClockAwareEvent::class);
        $event->method('getDispatcherType')->willReturn(null);

        // local dispatcher（default）が呼ばれることを確認
        $localDispatcher->expects($this->once())
            ->method('dispatchEvent')
            ->with($event, $this->app)
            ->willReturn(true);

        $sfnDispatcher->expects($this->never())
            ->method('dispatchEvent');

        $result = $dispatcher->dispatchEvent($event, $this->app);
        $this->assertTrue($result);
    }

    /**
     * T2.7: CompositeDispatcher_dispatchEvent_throws_on_unknown_type
     *
     * @test
     */
    public function it_throws_on_unknown_type()
    {
        $localDispatcher = $this->createMock(ScheduleDispatcherInterface::class);

        $dispatcher = new CompositeDispatcher(
            [
                'local' => $localDispatcher,
            ],
            'local'
        );

        $event = $this->createMock(ClockAwareEvent::class);
        $event->method('getDispatcherType')->willReturn('unknown');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown dispatcher type: unknown');

        $dispatcher->dispatchEvent($event, $this->app);
    }

    /**
     * T2.8: CompositeDispatcher_constructor_receives_defaultType_via_DI
     *
     * @test
     */
    public function it_receives_default_type_via_constructor()
    {
        $localDispatcher = $this->createMock(ScheduleDispatcherInterface::class);

        // defaultType を 'local' でインスタンス化
        $dispatcher = new CompositeDispatcher(
            [
                'local' => $localDispatcher,
            ],
            'local'
        );

        $event = $this->createMock(Event::class);

        $localDispatcher->expects($this->once())
            ->method('dispatchEvent')
            ->with($event, $this->app)
            ->willReturn(true);

        $dispatcher->dispatchEvent($event, $this->app);
    }
}
