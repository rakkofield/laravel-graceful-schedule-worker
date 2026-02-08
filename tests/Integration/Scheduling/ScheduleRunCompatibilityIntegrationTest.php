<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Scheduling;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * schedule:run と ClockAwareSchedule の互換性テスト
 *
 * skeleton の Application を bootstrap し、schedule:run が
 * ClockAwareSchedule を通して正しく動作することを検証する。
 *
 * @group skeleton
 */
class ScheduleRunCompatibilityIntegrationTest extends TestCase
{
    /** @var \Illuminate\Foundation\Application */
    private $app;

    /** @var Container|null */
    private $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();

        // 既存の Container インスタンスを退避
        $this->previousContainer = Container::getInstance();

        // skeleton の autoloader を追加で読み込み（Application クラス等を利用可能にする）
        require_once __DIR__ . '/../../../skeleton/vendor/autoload.php';

        // skeleton の Application を生成・ブートストラップ
        $this->app = require __DIR__ . '/../../../skeleton/bootstrap/app.php';
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->app->flush();
        }

        // Container インスタンスを復元
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    /**
     * @testdox TI.11 schedule:run resolves Schedule::class as ClockAwareSchedule
     */
    public function testScheduleRunResolvesClockAwareSchedule(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $this->assertInstanceOf(ClockAwareSchedule::class, $schedule);
    }

    /**
     * @testdox TI.12 Events from gracefulSchedule() are ClockAwareEvent instances
     */
    public function testEventsAreClockAwareEventInstances(): void
    {
        /** @var ClockAwareSchedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $events = $schedule->events();

        $this->assertNotEmpty($events);
        foreach ($events as $event) {
            $this->assertInstanceOf(ClockAwareEvent::class, $event);
        }
    }

    /**
     * @testdox TI.13 dueEvents() returns everyMinute event as due
     */
    public function testDueEventsReturnsEveryMinuteEventAsDue(): void
    {
        /** @var ClockAwareSchedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $dueEvents = $schedule->dueEvents($this->app)->all();

        $this->assertCount(1, $dueEvents);
        $this->assertStringContainsString('hello', $dueEvents[0]->command);
    }

    /**
     * @testdox TI.14 filtersPass() returns true on due ClockAwareEvent
     */
    public function testFiltersPassOnDueClockAwareEvent(): void
    {
        /** @var ClockAwareSchedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $dueEvents = $schedule->dueEvents($this->app)->all();

        $this->assertNotEmpty($dueEvents);
        $this->assertTrue($dueEvents[0]->filtersPass($this->app));
    }

    /**
     * @testdox TI.15 Artisan schedule:run exits 0 and processes due events
     */
    public function testArtisanScheduleRunProcessesDueEvents(): void
    {
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $exitCode = $kernel->call('schedule:run');
        $output = $kernel->output();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString(
            'No scheduled commands are ready to run',
            $output
        );
    }
}
