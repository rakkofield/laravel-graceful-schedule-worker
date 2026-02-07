<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionAlreadyExistsException;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionNameGeneratorInterface;
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

    /** @var ExecutionNameGeneratorInterface */
    private $nameGenerator;

    /**
     * @param StepFunctionsClientInterface $client
     * @param string $stateMachineArn
     * @param ExecutionNameGeneratorInterface $nameGenerator
     */
    public function __construct(
        StepFunctionsClientInterface $client,
        string $stateMachineArn,
        ExecutionNameGeneratorInterface $nameGenerator
    ) {
        $this->client = $client;
        $this->stateMachineArn = $stateMachineArn;
        $this->nameGenerator = $nameGenerator;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatchEvent(Event $event, Container $container, DateTimeInterface $dueAt): DispatchResultInterface
    {
        $mutexName = $event->mutexName();
        $command = $event->command;
        $executionName = $this->nameGenerator->generate($event, $dueAt);

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

            return new StartedStepFunctionsDispatchResult(
                $result->getExecutionArn(),
                $executionName,
                $mutexName,
                (string) $command,
                new DateTimeImmutable()
            );
        } catch (ExecutionAlreadyExistsException $e) {
            return new AlreadyRunningStepFunctionsDispatchResult(
                $executionName,
                $mutexName,
                (string) $command,
                new DateTimeImmutable()
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

    /**
     * {@inheritdoc}
     *
     * Step Functions はリモート実行のため、ローカルでの cleanup は不要
     */
    public function cleanup(): void
    {
        // no-op: Step Functions はリモートで実行されるため
    }

    /**
     * {@inheritdoc}
     *
     * Step Functions はリモート実行のため、ローカルでの stopAll は不要
     */
    public function stopAll(): void
    {
        // no-op: Step Functions はリモートで実行されるため
    }
}
