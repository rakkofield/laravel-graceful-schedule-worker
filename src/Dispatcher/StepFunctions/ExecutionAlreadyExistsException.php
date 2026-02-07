<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

/**
 * 同名の Execution が既に存在する場合の例外
 *
 * AWS Step Functions の ExecutionAlreadyExists エラーに対応します。
 */
class ExecutionAlreadyExistsException extends StepFunctionsException
{
    /**
     * @param string $executionName 既存の Execution 名
     * @param string $message エラーメッセージ
     */
    public function __construct(string $executionName, string $message = '')
    {
        parent::__construct($message ?: "Execution '{$executionName}' already exists");
    }
}
