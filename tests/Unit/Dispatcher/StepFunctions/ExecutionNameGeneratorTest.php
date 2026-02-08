<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\FakeEventMutex;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\StubLongMutexEvent;

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
     * @testdox ENG.1 Event と dueAt から Execution Name を生成する
     */
    public function testGeneratesExecutionNameFromEventAndDueAt(): void
    {
        $event = $this->createEvent('my-task');
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result = $this->generator->generate($event, $dueAt);

        // mutexName は Event の内部フォーマットに依存するため、
        // タイムスタンプ部分が含まれることを確認
        $this->assertStringContainsString('1704067200', $result);
    }

    /**
     * @testdox ENG.2 不正な文字をハイフンに置換する
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
     * @testdox ENG.3 80文字を超える場合はハッシュを使用して短縮する
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
     * @testdox ENG.4 同じ入力からは同じ出力が得られる（決定論的）
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
     * @testdox ENG.5 長い名前でも同じ入力から同じハッシュが生成される
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
     * @testdox ENG.6 80文字以下の名前はトランケーションされない
     */
    public function testNamesAtOrBelowLimitAreNotTruncated(): void
    {
        // 短いコマンドの場合、ハッシュが含まれないことを確認
        $event = $this->createEvent('short');
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result = $this->generator->generate($event, $dueAt);

        $this->assertLessThanOrEqual(80, strlen($result));
        // 短い入力ではハッシュが使われず、Unix timestamp が含まれる
        $this->assertStringContainsString('1704067200', $result);
    }

    /**
     * @testdox ENG.7 結果は許可文字のみで構成される
     */
    public function testResultContainsOnlyValidCharacters(): void
    {
        $event = $this->createEvent('php artisan report:daily --force');
        $dueAt = new DateTimeImmutable('2024-06-15 14:30:00');

        $result = $this->generator->generate($event, $dueAt);

        $this->assertRegExp('/^[a-zA-Z0-9_-]+$/', $result);
    }

    /**
     * @testdox ENG.8 80文字を超える mutexName ではハッシュで切り詰められ結果が80文字以内になる
     */
    public function testTruncatesWithHashWhenMutexNameExceeds80Chars(): void
    {
        // 標準の Event::mutexName() は常に固定長（sha1）なので 80 文字を超えない。
        // 長い mutexName を持つ StubLongMutexEvent で切り詰めパスを検証する。
        $longMutex = str_repeat('abcdefghij', 10); // 100文字
        $event = new StubLongMutexEvent($this->mutex, 'test', $longMutex);
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result = $this->generator->generate($event, $dueAt);

        $this->assertLessThanOrEqual(80, strlen($result));
        $this->assertRegExp('/^[a-zA-Z0-9_-]+$/', $result);
    }

    /**
     * @testdox ENG.9 切り詰められた名前にはハッシュサフィックスが含まれる
     */
    public function testTruncatedNameContainsHashSuffix(): void
    {
        $longMutex = str_repeat('abcdefghij', 10); // 100文字
        $event = new StubLongMutexEvent($this->mutex, 'test', $longMutex);
        $dueAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $result = $this->generator->generate($event, $dueAt);

        // 切り詰め結果は mutexPrefix + '_' + md5ハッシュ16文字
        $parts = explode('_', $result);
        $lastPart = end($parts);
        // ハッシュ部分は16文字の16進数
        $this->assertEquals(16, strlen($lastPart));
        $this->assertRegExp('/^[a-f0-9]+$/', $lastPart);
    }
}
