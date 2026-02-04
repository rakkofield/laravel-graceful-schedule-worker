<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Clock\ClockInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionAlreadyExistsException;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGenerator;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsClientInterface;

/**
 * Step Functions を使用したイベントディスパッチャー
 *
 * StartExecution API を呼び出し、State Machine でタスクを実行します。
 */
class StepFunctionsDispatcher implements ScheduleDispatcherInterface
{
    /** @var StepFunctionsClientInterface */
    private $client;

    /** @var string */
    private $stateMachineArn;

    /** @var ClockInterface */
    private $clock;

    /** @var ExecutionNameGenerator */
    private $nameGenerator;

    /**
     * @param StepFunctionsClientInterface $client
     * @param string $stateMachineArn
     * @param ClockInterface $clock
     * @param ExecutionNameGenerator $nameGenerator
     */
    public function __construct(
        StepFunctionsClientInterface $client,
        string $stateMachineArn,
        ClockInterface $clock,
        ExecutionNameGenerator $nameGenerator
    ) {
        $this->client = $client;
        $this->stateMachineArn = $stateMachineArn;
        $this->clock = $clock;
        $this->nameGenerator = $nameGenerator;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatchEvent(Event $event, Container $container): DispatchResultInterface
    {
        $mutexName = $event->mutexName();
        $command = $event->command;
        $dueAt = $this->clock->now();
        $timestamp = $dueAt->format('Y-m-d\TH-i-s');
        $executionName = $this->nameGenerator->generate($mutexName, $timestamp);

        try {
            $input = json_encode([
                'command' => $command,
                'mutexName' => $mutexName,
                'dueAt' => $dueAt->format(\DateTimeInterface::ATOM),
            ]);

            if ($input === false) {
                throw new \RuntimeException('Failed to encode input JSON: ' . json_last_error_msg());
            }

            $result = $this->client->startExecution([
                'stateMachineArn' => $this->stateMachineArn,
                'name' => $executionName,
                'input' => $input,
            ]);

            return StartedStepFunctionsDispatchResult::success(
                $result->getExecutionArn(),
                $executionName,
                $mutexName,
                (string) $command
            );
        } catch (ExecutionAlreadyExistsException $e) {
            return StartedStepFunctionsDispatchResult::alreadyRunning(
                $executionName,
                $mutexName,
                (string) $command
            );
        } catch (\Exception $e) {
            // StepFunctionsException およびその他の Exception を処理
            // Note: \Error は catch されずに再スローされる（致命的エラーは呼び出し元に伝播）
            return FailedStepFunctionsDispatchResult::failed(
                $executionName,
                $mutexName,
                $command,
                get_class($e) . ': ' . $e->getMessage(),
                $e
            );
        }
    }
}
