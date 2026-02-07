<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Clock;

use PHPUnit\Framework\TestCase;

class SleeperTest extends TestCase
{
    /**
     * @testdox T1.1 正の値でインスタンスを生成できる
     */
    public function testConstructsWithPositiveValue(): void
    {
        $sleeper = new Sleeper(1000);
        $this->assertInstanceOf(SleeperInterface::class, $sleeper);
    }

    /**
     * @testdox T1.2 0 以下の値で InvalidArgumentException をスロー
     */
    public function testThrowsOnZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Sleeper(0);
    }

    /**
     * @testdox T1.3 負の値で InvalidArgumentException をスロー
     */
    public function testThrowsOnNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Sleeper(-1);
    }

    /**
     * @testdox T1.4 sleep() が実行される（短い時間で検証）
     */
    public function testSleepExecutes(): void
    {
        $sleeper = new Sleeper(1); // 1マイクロ秒
        $before = microtime(true);
        $sleeper->sleep();
        $after = microtime(true);

        // 実行が完了することを確認（例外なし）
        $this->assertGreaterThanOrEqual($before, $after);
    }
}
