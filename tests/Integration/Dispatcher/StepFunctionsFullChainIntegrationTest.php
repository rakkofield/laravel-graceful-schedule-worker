<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Aws\Sfn\SfnClient;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\AwsSfnClientAdapter;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeCacheStore;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeLockProvider;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\SpyLogger;
use RakkoInc\LaravelGracefulScheduleWorker\Tracker\CacheExecutionTracker;

/**
 * TrackingDispatcher + StepFunctionsDispatcher + moto のフルチェーン統合テスト
 *
 * @group integration
 * @group stepfunctions
 */
class StepFunctionsFullChainIntegrationTest extends TestCase
{
    /** @var string */
    private static $stateMachineArn;

    /** @var Container */
    private $container;

    /** @var FakeEventMutex */
    private $mutex;

    /** @var SfnClient|null */
    private $sfnClient;

    /** @var string */
    private $endpoint;

    /** @var FakeLockProvider */
    private $lockProvider;

    /** @var FakeCacheStore */
    private $cache;

    /** @var SpyLogger */
    private $logger;

    /** @var CacheExecutionTracker */
    private $tracker;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$stateMachineArn =
            'arn:aws:states:ap-northeast-1:000000000000:stateMachine:GracefulSchedulerStateMachine';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->endpoint = getenv('SFN_ENDPOINT') ?: 'http://localhost:5001';

        if (!$this->isSfnEndpointAvailable()) {
            $this->markTestSkipped('Step Functions endpoint is not available: ' . $this->endpoint);
        }

        $this->container = new Container();
        Container::setInstance($this->container);
        $this->mutex = new FakeEventMutex();
        $this->container->bind(EventMutex::class, function () {
            return $this->mutex;
        });

        $this->sfnClient = new SfnClient([
            'region' => 'ap-northeast-1',
            'version' => 'latest',
            'endpoint' => $this->endpoint,
            'credentials' => [
                'key' => 'test',
                'secret' => 'test',
            ],
            'suppress_php_deprecation_warning' => true,
        ]);

        $this->ensureStateMachineExists();

        $this->lockProvider = new FakeLockProvider();
        $this->cache = new FakeCacheStore($this->lockProvider);
        $this->logger = new SpyLogger();
        $this->tracker = new CacheExecutionTracker($this->cache, $this->lockProvider, $this->logger);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function ensureStateMachineExists(): void
    {
        $definition = file_get_contents(__DIR__ . '/../../StepFunctions/state-machine.json');

        try {
            $this->sfnClient->createStateMachine([
                'name' => 'GracefulSchedulerStateMachine',
                'definition' => $definition,
                'roleArn' => 'arn:aws:iam::000000000000:role/stepfunctions-role',
            ]);
        } catch (\Aws\Exception\AwsException $e) {
            if ($e->getAwsErrorCode() !== 'StateMachineAlreadyExists') {
                throw $e;
            }
        }
    }

    /**
     * @return bool
     */
    private function isSfnEndpointAvailable(): bool
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 2,
            ],
        ]);

        $healthUrl = $this->endpoint . '/moto-api/';
        $response = @file_get_contents($healthUrl, false, $context);
        return $response !== false;
    }

    /**
     * @param ExecutionNameGenerator|null $nameGenerator
     * @return TrackingDispatcher
     */
    private function createTrackingDispatcher(
        ?ExecutionNameGenerator $nameGenerator = null
    ): TrackingDispatcher {
        $adapter = new AwsSfnClientAdapter($this->sfnClient);
        $sfnDispatcher = new StepFunctionsDispatcher(
            $adapter,
            self::$stateMachineArn,
            $nameGenerator ?? new ExecutionNameGenerator()
        );

        return new TrackingDispatcher($sfnDispatcher, $this->tracker, $this->logger);
    }

    /**
     * @param string $command
     * @return Event
     */
    private function createEvent(string $command): Event
    {
        return new Event($this->mutex, $command);
    }

    /**
     * @testdox T11.1 TrackingDispatcher with real Step Functions dispatch
     */
    public function testTrackingDispatcherWithRealStepFunctionsDispatch(): void
    {
        $trackingDispatcher = $this->createTrackingDispatcher();
        $event = $this->createEvent('php artisan report:daily');
        $dueAt = new DateTimeImmutable();

        $result = $trackingDispatcher->dispatchEvent($event, $this->container, $dueAt);

        $this->assertInstanceOf(StartedDispatchResultInterface::class, $result);
        $this->assertInstanceOf(StartedStepFunctionsDispatchResult::class, $result);

        // markExecuted 確認
        $key = 'schedule:tracker:last:' . $event->mutexName();
        $this->assertTrue($this->cache->has($key));
    }

    /**
     * @testdox T11.2 AlreadyRunning through full chain
     */
    public function testAlreadyRunningThroughFullChain(): void
    {
        $uniqueTime = new DateTimeImmutable();
        $fixedName = 'test-chain-dup-' . $uniqueTime->format('U-u');
        $nameGenerator = new FixedExecutionNameGenerator($fixedName);

        $trackingDispatcher = $this->createTrackingDispatcher($nameGenerator);
        $uniqueCommand = 'php artisan test:chain-dup-' . $uniqueTime->format('U.u');
        $event = $this->createEvent($uniqueCommand);

        // 1回目: Started
        $dueAt1 = $uniqueTime;
        $result1 = $trackingDispatcher->dispatchEvent($event, $this->container, $dueAt1);
        $this->assertInstanceOf(StartedStepFunctionsDispatchResult::class, $result1);

        // 2回目: AlreadyRunning（異なる dueAt = 異なる input）
        $dueAt2 = $uniqueTime->modify('+1 second');
        $result2 = $trackingDispatcher->dispatchEvent($event, $this->container, $dueAt2);
        $this->assertInstanceOf(AlreadyRunningDispatchResultInterface::class, $result2);

        // AlreadyRunning でも markExecuted される
        $key = 'schedule:tracker:last:' . $event->mutexName();
        $lastTs = (int) $this->cache->get($key);
        $this->assertSame($dueAt2->getTimestamp(), $lastTs);
    }

    /**
     * @testdox T11.3 Step Functions API error propagation
     */
    public function testStepFunctionsApiErrorPropagation(): void
    {
        $adapter = new AwsSfnClientAdapter($this->sfnClient);
        $badDispatcher = new StepFunctionsDispatcher(
            $adapter,
            'arn:aws:states:ap-northeast-1:000000000000:stateMachine:NonExistent',
            new ExecutionNameGenerator()
        );
        $badTrackingDispatcher = new TrackingDispatcher($badDispatcher, $this->tracker, $this->logger);

        $event = $this->createEvent('php artisan test:error');
        $dueAt = new DateTimeImmutable();

        $result = $badTrackingDispatcher->dispatchEvent($event, $this->container, $dueAt);

        $this->assertInstanceOf(FailedDispatchResultInterface::class, $result);
        $this->assertTrue($this->logger->hasLogContaining('error', 'Failed to dispatch event'));

        // markExecuted されていない
        $key = 'schedule:tracker:last:' . $event->mutexName();
        $this->assertFalse($this->cache->has($key));
    }
}
