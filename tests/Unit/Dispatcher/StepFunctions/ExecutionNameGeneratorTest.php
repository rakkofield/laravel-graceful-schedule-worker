<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tests\Unit\Dispatcher\StepFunctions;

use PHPUnit\Framework\TestCase;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGenerator;

/**
 * @testdox ExecutionNameGenerator
 */
class ExecutionNameGeneratorTest extends TestCase
{
    /** @var ExecutionNameGenerator */
    private $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ExecutionNameGenerator();
    }

    /**
     * @testdox T4.1.1 mutexName と timestamp から Execution Name を生成する
     */
    public function testGeneratesExecutionNameFromMutexAndTimestamp(): void
    {
        $result = $this->generator->generate('my-task', '2024-01-01T00-00-00');

        $this->assertSame('my-task_2024-01-01T00-00-00', $result);
    }

    /**
     * @testdox T4.1.2 不正な文字をハイフンに置換する
     */
    public function testSanitizesInvalidCharacters(): void
    {
        $result = $this->generator->generate('framework/schedule:run', '2024/01/01 00:00:00');

        $this->assertSame('framework-schedule-run_2024-01-01-00-00-00', $result);
    }

    /**
     * @testdox T4.1.3 許可された文字（a-z, A-Z, 0-9, -, _）はそのまま保持する
     */
    public function testPreservesValidCharacters(): void
    {
        $result = $this->generator->generate('Task_Name-123', 'ABC-xyz_456');

        $this->assertSame('Task_Name-123_ABC-xyz_456', $result);
    }

    /**
     * @testdox T4.1.4 80文字を超える場合はハッシュを使用して短縮する
     */
    public function testTruncatesLongNamesWithHash(): void
    {
        $longMutexName = str_repeat('a', 100);
        $timestamp = '2024-01-01T00-00-00';

        $result = $this->generator->generate($longMutexName, $timestamp);

        $this->assertLessThanOrEqual(80, strlen($result));
        // ハッシュ（16文字）が含まれていることを確認
        $this->assertRegExp('/^a+_[a-f0-9]{16}$/', $result);
    }

    /**
     * @testdox T4.1.5 ちょうど80文字の場合は短縮しない
     */
    public function testDoesNotTruncateExactly80Characters(): void
    {
        // 80文字になるように調整（mutex + _ + timestamp = 80）
        $mutexName = str_repeat('a', 60);
        $timestamp = str_repeat('b', 19); // 60 + 1 + 19 = 80

        $result = $this->generator->generate($mutexName, $timestamp);

        $this->assertSame(80, strlen($result));
        $this->assertSame($mutexName . '_' . $timestamp, $result);
    }

    /**
     * @testdox T4.1.6 同じ入力からは同じ出力が得られる（決定論的）
     */
    public function testIsDeterministic(): void
    {
        $mutexName = 'my-task';
        $timestamp = '2024-01-01T00-00-00';

        $result1 = $this->generator->generate($mutexName, $timestamp);
        $result2 = $this->generator->generate($mutexName, $timestamp);

        $this->assertSame($result1, $result2);
    }

    /**
     * @testdox T4.1.7 長い名前でも同じ入力から同じハッシュが生成される
     */
    public function testLongNamesAreDeterministic(): void
    {
        $longMutexName = str_repeat('x', 100);
        $timestamp = '2024-01-01T00-00-00';

        $result1 = $this->generator->generate($longMutexName, $timestamp);
        $result2 = $this->generator->generate($longMutexName, $timestamp);

        $this->assertSame($result1, $result2);
    }

    /**
     * @testdox T4.1.8 空文字列でも動作する
     */
    public function testHandlesEmptyStrings(): void
    {
        $result = $this->generator->generate('', '');

        $this->assertSame('_', $result);
    }
}
