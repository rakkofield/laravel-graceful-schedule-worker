<?php

namespace RakkoInc\LaravelGracefulScheduleWorker\Tests\Unit\Dispatcher;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\LocalDispatcher;

class LocalDispatcherTest extends TestCase
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
     * T2.1: LocalDispatcher_dispatchEvent_executes_event
     *
     * @test
     */
    public function it_executes_event()
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createMock(Event::class);

        // Event::run() が呼ばれることを確認
        $event->expects($this->once())
            ->method('run')
            ->with($this->app);

        $result = $dispatcher->dispatchEvent($event, $this->app);

        $this->assertTrue($result);
    }

    /**
     * T2.2: LocalDispatcher_dispatchEvent_passes_event_correctly
     *
     * @test
     */
    public function it_passes_event_correctly()
    {
        $dispatcher = new LocalDispatcher();
        $event = $this->createMock(Event::class);

        // Event::run() に正しい Application が渡されることを確認
        $event->expects($this->once())
            ->method('run')
            ->with($this->identicalTo($this->app));

        $dispatcher->dispatchEvent($event, $this->app);
    }
}
