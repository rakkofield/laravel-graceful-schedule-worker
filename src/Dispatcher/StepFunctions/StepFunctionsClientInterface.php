<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

/**
 * Step Functions クライアントのインターフェース
 *
 * AWS SDK への直接依存を避けるため、必要なメソッドのみを定義します。
 */
interface StepFunctionsClientInterface
{
    /**
     * State Machine の Execution を開始する
     *
     * @param array{stateMachineArn: string, name?: string, input?: string} $args
     * @return StartExecutionResult
     * @throws ExecutionAlreadyExistsException 同名の Execution が既に存在する場合
     * @throws StepFunctionsException その他の Step Functions エラー
     */
    public function startExecution(array $args): StartExecutionResult;
}
