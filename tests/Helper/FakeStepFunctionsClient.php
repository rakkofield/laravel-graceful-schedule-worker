<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Helper;

use DateTimeImmutable;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\ExecutionAlreadyExistsException;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsClientInterface;
use RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions\StepFunctionsException;

/**
 * テスト用の Fake Step Functions クライアント
 */
class FakeStepFunctionsClient implements StepFunctionsClientInterface
{
    /** @var array<int, array{stateMachineArn: string, name: string, input: string, executionArn: string, startDate: DateTimeImmutable}> */
    private $executions = [];

    /** @var array<string, true> */
    private $existingExecutions = [];

    /** @var StepFunctionsException|null */
    private $nextError = null;

    /**
     * {@inheritdoc}
     */
    public function startExecution(array $args): array
    {
        if ($this->nextError !== null) {
            $error = $this->nextError;
            $this->nextError = null;
            throw $error;
        }

        $name = $args['name'] ?? 'unnamed-' . count($this->executions);

        if (isset($this->existingExecutions[$name])) {
            throw new ExecutionAlreadyExistsException($name);
        }

        $executionArn = sprintf(
            'arn:aws:states:ap-northeast-1:000000000000:execution:test-state-machine:%s',
            $name
        );
        $startDate = new DateTimeImmutable();

        $this->executions[] = [
            'stateMachineArn' => $args['stateMachineArn'],
            'name' => $name,
            'input' => $args['input'] ?? '{}',
            'executionArn' => $executionArn,
            'startDate' => $startDate,
        ];

        // 同じ名前での再実行を防ぐ
        $this->existingExecutions[$name] = true;

        return [
            'executionArn' => $executionArn,
            'startDate' => $startDate,
        ];
    }

    /**
     * 次回の startExecution で ExecutionAlreadyExists をスローするよう設定
     *
     * @param string $executionName
     * @return void
     */
    public function willThrowExecutionAlreadyExists(string $executionName): void
    {
        $this->nextError = new ExecutionAlreadyExistsException($executionName);
    }

    /**
     * 次回の startExecution で一般エラーをスローするよう設定
     *
     * @param string $message
     * @return void
     */
    public function willThrowError(string $message): void
    {
        $this->nextError = new StepFunctionsException($message);
    }

    /**
     * 既存の Execution を追加（同名の startExecution が失敗するようになる）
     *
     * @param string $name
     * @return void
     */
    public function addExistingExecution(string $name): void
    {
        $this->existingExecutions[$name] = true;
    }

    /**
     * 実行された Execution の一覧を取得
     *
     * @return array<int, array{
     *     stateMachineArn: string,
     *     name: string,
     *     input: string,
     *     executionArn: string,
     *     startDate: DateTimeImmutable
     * }>
     */
    public function getExecutions(): array
    {
        return $this->executions;
    }

    /**
     * 最後に実行された Execution を取得
     *
     * @return array{
     *     stateMachineArn: string,
     *     name: string,
     *     input: string,
     *     executionArn: string,
     *     startDate: DateTimeImmutable
     * }|null
     */
    public function getLastExecution(): ?array
    {
        if (empty($this->executions)) {
            return null;
        }
        return $this->executions[count($this->executions) - 1];
    }

    /**
     * 状態をリセット
     *
     * @return void
     */
    public function reset(): void
    {
        $this->executions = [];
        $this->existingExecutions = [];
        $this->nextError = null;
    }
}
