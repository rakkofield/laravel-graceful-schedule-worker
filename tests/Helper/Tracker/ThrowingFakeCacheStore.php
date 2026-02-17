<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Tracker;

/**
 * FakeCacheStore extension that throws exceptions on put()
 *
 * Used to test error handling when markExecuted fails
 */
class ThrowingFakeCacheStore extends FakeCacheStore
{
    /** @var \Exception|null */
    private $putException;

    /**
     * Configure put() to throw an exception
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
