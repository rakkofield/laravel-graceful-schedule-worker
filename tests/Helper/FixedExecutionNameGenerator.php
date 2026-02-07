<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Helper;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGeneratorInterface;

/**
 * テスト用の固定 Execution Name Generator
 *
 * 常に指定された固定の名前を返す。
 * ExecutionAlreadyExists のテストで、同じ name だが異なる input を送信するために使用。
 */
class FixedExecutionNameGenerator implements ExecutionNameGeneratorInterface
{
    /** @var string */
    private $fixedName;

    /**
     * @param string $fixedName 返す固定の名前
     */
    public function __construct(string $fixedName)
    {
        $this->fixedName = $fixedName;
    }

    /**
     * {@inheritdoc}
     */
    public function generate(Event $event, DateTimeInterface $dueAt): string
    {
        return $this->fixedName;
    }
}
