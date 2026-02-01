<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher;

use DateTimeImmutable;
use Symfony\Component\Process\Process;

/**
 * LocalDispatcher 用の結果クラス
 *
 * Process オブジェクトを保持し、バックグラウンドプロセスの管理を可能にします。
 */
class LocalDispatchResult implements DispatchResultInterface
{
    /** @var Process|null */
    private $process;

    /** @var string */
    private $eventIdentifier;

    /** @var string */
    private $eventCommand;

    /** @var DateTimeImmutable */
    private $dispatchedAt;

    /** @var string|null */
    private $error;

    /**
     * @param Process|null $process
     * @param string $eventIdentifier
     * @param string $eventCommand
     * @param DateTimeImmutable $dispatchedAt
     * @param string|null $error
     */
    private function __construct(
        $process,
        string $eventIdentifier,
        string $eventCommand,
        DateTimeImmutable $dispatchedAt,
        $error
    ) {
        $this->process = $process;
        $this->eventIdentifier = $eventIdentifier;
        $this->eventCommand = $eventCommand;
        $this->dispatchedAt = $dispatchedAt;
        $this->error = $error;
    }

    /**
     * 成功した場合の結果を作成
     *
     * @param Process $process
     * @param string $identifier
     * @param string $command
     * @return self
     */
    public static function success(Process $process, string $identifier, string $command): self
    {
        return new self(
            $process,
            $identifier,
            $command,
            new DateTimeImmutable(),
            null
        );
    }

    /**
     * 失敗した場合の結果を作成
     *
     * @param string $identifier
     * @param string|null $command
     * @param string $error
     * @return self
     */
    public static function failed(string $identifier, ?string $command, string $error): self
    {
        return new self(
            null,
            $identifier,
            $command ?? '',
            new DateTimeImmutable(),
            $error
        );
    }

    /**
     * {@inheritdoc}
     */
    public function isStarted(): bool
    {
        return $this->process !== null && $this->error === null;
    }

    /**
     * {@inheritdoc}
     *
     * @return string|null
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * {@inheritdoc}
     */
    public function getEventIdentifier(): string
    {
        return $this->eventIdentifier;
    }

    /**
     * {@inheritdoc}
     */
    public function getEventCommand(): string
    {
        return $this->eventCommand;
    }

    /**
     * {@inheritdoc}
     */
    public function getDispatcherType(): string
    {
        return 'local';
    }

    /**
     * {@inheritdoc}
     */
    public function getDispatchedAt(): DateTimeImmutable
    {
        return $this->dispatchedAt;
    }

    /**
     * Process オブジェクトを取得
     *
     * @return Process|null
     */
    public function getProcess(): ?Process
    {
        return $this->process;
    }

    /**
     * プロセスが実行中かどうか
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->process !== null && $this->process->isRunning();
    }

    /**
     * プロセスの終了コードを取得
     *
     * @return int|null
     */
    public function getExitCode(): ?int
    {
        if ($this->process === null) {
            return null;
        }

        return $this->process->getExitCode();
    }

    /**
     * {@inheritdoc}
     *
     * LocalDispatcher では例外は保持しません。
     */
    public function getException(): ?\Throwable
    {
        return null;
    }
}
