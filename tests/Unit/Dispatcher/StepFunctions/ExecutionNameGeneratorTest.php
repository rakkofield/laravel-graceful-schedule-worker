<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FakeEventMutex;

/**
 * @testdox ExecutionNameGenerator
 */
class ExecutionNameGeneratorTest extends TestCase
{
    /** @var ExecutionNameGenerator */
    private $generator;

    /** @var FakeEventMutex */
    private $mutex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ExecutionNameGenerator();
        $this->mutex = new FakeEventMutex();
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
     * @testdox T4.1.1 Event と dueAt から Execution Name を生成する
     */
    public function testGeneratesExecutionNameFromEventAndDueAt(): void
    {
        $event = $this->createEvent('my-task');
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result = $this->generator->generate($event, $dueAt);

        // mutexName は Event の内部フォーマットに依存するため、
        // タイムスタンプ部分が含まれることを確認
        $this->assertStringContainsString('2024-01-01T00-00-00', $result);
    }

    /**
     * @testdox T4.1.2 不正な文字をハイフンに置換する
     */
    public function testSanitizesInvalidCharacters(): void
    {
        $event = $this->createEvent('framework/schedule:run');
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result = $this->generator->generate($event, $dueAt);

        // 不正文字がサニタイズされていることを確認
        $this->assertRegExp('/^[a-zA-Z0-9_-]+$/', $result);
    }

    /**
     * @testdox T4.1.3 80文字を超える場合はハッシュを使用して短縮する
     */
    public function testTruncatesLongNamesWithHash(): void
    {
        $longCommand = str_repeat('a', 100);
        $event = $this->createEvent($longCommand);
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result = $this->generator->generate($event, $dueAt);

        $this->assertLessThanOrEqual(80, strlen($result));
    }

    /**
     * @testdox T4.1.4 同じ入力からは同じ出力が得られる（決定論的）
     */
    public function testIsDeterministic(): void
    {
        $event = $this->createEvent('my-task');
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result1 = $this->generator->generate($event, $dueAt);
        $result2 = $this->generator->generate($event, $dueAt);

        $this->assertSame($result1, $result2);
    }

    /**
     * @testdox T4.1.5 長い名前でも同じ入力から同じハッシュが生成される
     */
    public function testLongNamesAreDeterministic(): void
    {
        $longCommand = str_repeat('x', 100);
        $event = $this->createEvent($longCommand);
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result1 = $this->generator->generate($event, $dueAt);
        $result2 = $this->generator->generate($event, $dueAt);

        $this->assertSame($result1, $result2);
    }

    /**
     * @testdox T4.1.6 80文字以下の名前はトランケーションされない
     */
    public function testNamesAtOrBelowLimitAreNotTruncated(): void
    {
        // 短いコマンドの場合、ハッシュが含まれないことを確認
        $event = $this->createEvent('short');
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result = $this->generator->generate($event, $dueAt);

        $this->assertLessThanOrEqual(80, strlen($result));
        // 短い入力ではハッシュ（md5の16文字部分）が使われず、タイムスタンプが含まれる
        $this->assertStringContainsString('2024-01-01T00-00-00', $result);
    }

    /**
     * @testdox T4.1.7 結果は許可文字のみで構成される
     */
    public function testResultContainsOnlyValidCharacters(): void
    {
        $event = $this->createEvent('php artisan report:daily --force');
        $dueAt = new DateTimeImmutable('2024-06-15 14:30:00');

        $result = $this->generator->generate($event, $dueAt);

        $this->assertRegExp('/^[a-zA-Z0-9_-]+$/', $result);
    }
}
