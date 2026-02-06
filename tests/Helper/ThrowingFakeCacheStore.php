<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Helper;

/**
 * put() で例外をスローする FakeCacheStore 拡張
 *
 * markExecuted 失敗時のエラーハンドリングをテストするために使用
 */
class ThrowingFakeCacheStore extends FakeCacheStore
{
    /** @var \Exception|null */
    private $putException;

    /**
     * put() で例外をスローするように設定
     *
     * @param \Exception $exception
     * @return void
     */
    public function willThrowOnPut(\Exception $exception): void
    {
        $this->putException = $exception;
    }

    /**
     * {@inheritdoc}
     */
    public function put($key, $value, $ttl = null)
    {
        if ($this->putException !== null) {
            throw $this->putException;
        }
        return parent::put($key, $value, $ttl);
    }
}
