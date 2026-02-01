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
    /** @var string */
    private $executionName;

    /**
     * @param string $executionName 既存の Execution 名
     * @param string $message エラーメッセージ
     */
    public function __construct(string $executionName, string $message = '')
    {
        $this->executionName = $executionName;
        parent::__construct($message ?: "Execution '{$executionName}' already exists");
    }

    /**
     * 既存の Execution 名を取得
     *
     * @return string
     */
    public function getExecutionName(): string
    {
        return $this->executionName;
    }
}
