<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\Sfn\SfnClient;

/**
 * テスト用の SfnClient スタブ
 *
 * AWS SDK の SfnClient を継承し、API 呼び出しをスタブ化します。
 */
class StubSfnClient extends SfnClient
{
    /** @var array<string, mixed>|null */
    private $successResult;

    /** @var string|null */
    private $errorCode;

    /** @var string|null */
    private $errorMessage;

    /**
     * @param array<string, mixed>|null $successResult 成功時に返す結果
     * @param string|null $errorCode エラーコード（設定するとエラーをスロー）
     * @param string|null $errorMessage エラーメッセージ
     */
    public function __construct(
        ?array $successResult = null,
        ?string $errorCode = null,
        ?string $errorMessage = null
    ) {
        // 親クラスのコンストラクタを呼ばないことで、AWS SDK の初期化をスキップ
        $this->successResult = $successResult;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
    }

    /**
     * @param array<string, mixed> $args
     * @return Result
     * @throws AwsException
     */
    public function startExecution(array $args = []): Result
    {
        if ($this->errorCode !== null) {
            $command = new Command('StartExecution');
            throw new AwsException(
                $this->errorMessage ?? 'AWS Error',
                $command,
                [
                    'code' => $this->errorCode,
                    'message' => $this->errorMessage ?? 'AWS Error',
                ]
            );
        }

        return new Result($this->successResult ?? []);
    }
}
