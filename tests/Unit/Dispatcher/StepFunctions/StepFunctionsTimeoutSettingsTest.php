<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\Dispatcher\StepFunctions;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @testdox StepFunctionsTimeoutSettings
 */
class StepFunctionsTimeoutSettingsTest extends TestCase
{
    private const DUE_AT = 1705282200;

    /**
     * @testdox STS.1 Getters return constructor values
     */
    public function testGettersReturnConstructorValues(): void
    {
        $settings = new StepFunctionsTimeoutSettings(3600, 60, 30);

        $this->assertSame(3600, $settings->getLockTtlSeconds());
        $this->assertSame(60, $settings->getLockReleaseBufferSeconds());
        $this->assertSame(30, $settings->getMinTaskTimeoutSeconds());
    }

    /**
     * @testdox STS.2 minTaskTimeout defaults to 60 seconds
     */
    public function testMinTaskTimeoutDefaultsToSixtySeconds(): void
    {
        $settings = new StepFunctionsTimeoutSettings(3600, 60);

        $this->assertSame(60, $settings->getMinTaskTimeoutSeconds());
    }

    /**
     * @testdox STS.3 Accepts the smallest coherent triple
     */
    public function testAcceptsSmallestCoherentTriple(): void
    {
        $settings = new StepFunctionsTimeoutSettings(2, 1, 1);

        $this->assertSame(2, $settings->getLockTtlSeconds());
        $this->assertSame(1, $settings->getLockReleaseBufferSeconds());
        $this->assertSame(1, $settings->getMinTaskTimeoutSeconds());
    }

    /**
     * @testdox STS.4 Rejects a lockTtl below 1 second
     */
    public function testRejectsLockTtlBelowOneSecond(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lock_ttl must be at least 1 second, got 0');

        new StepFunctionsTimeoutSettings(0, 60);
    }

    /**
     * @testdox STS.5 Rejects a negative lockTtl
     */
    public function testRejectsNegativeLockTtl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lock_ttl must be at least 1 second, got -1');

        new StepFunctionsTimeoutSettings(-1, 60);
    }

    /**
     * @testdox STS.6 Rejects a zero lockReleaseBuffer because the release path needs room
     */
    public function testRejectsZeroLockReleaseBuffer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lock_release_buffer must be at least 1 second, got 0');

        new StepFunctionsTimeoutSettings(3600, 0);
    }

    /**
     * @testdox STS.7 Rejects a negative lockReleaseBuffer
     */
    public function testRejectsNegativeLockReleaseBuffer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lock_release_buffer must be at least 1 second, got -60');

        new StepFunctionsTimeoutSettings(3600, -60);
    }

    /**
     * @testdox STS.8 Rejects a non-positive minTaskTimeout
     */
    public function testRejectsNonPositiveMinTaskTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('min_task_timeout must be at least 1 second, got 0');

        new StepFunctionsTimeoutSettings(3600, 60, 0);
    }

    /**
     * @testdox STS.9 Rejects a lockTtl equal to the lockReleaseBuffer
     */
    public function testRejectsLockTtlEqualToBuffer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lock_ttl (60) must be greater than lock_release_buffer (60)');

        new StepFunctionsTimeoutSettings(60, 60, 1);
    }

    /**
     * @testdox STS.10 Rejects a lockTtl below the lockReleaseBuffer
     */
    public function testRejectsLockTtlBelowBuffer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lock_ttl (30) must be greater than lock_release_buffer (600)');

        new StepFunctionsTimeoutSettings(30, 600, 1);
    }

    /**
     * @testdox STS.11 Rejects a minTaskTimeout exceeding lockTtl minus lockReleaseBuffer
     */
    public function testRejectsMinTaskTimeoutExceedingBudget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('min_task_timeout (3600) must not exceed lock_ttl - lock_release_buffer');

        new StepFunctionsTimeoutSettings(3600, 60, 3600);
    }

    /**
     * @testdox STS.12 Defaults form a coherent triple
     */
    public function testDefaultsFormCoherentTriple(): void
    {
        $settings = new StepFunctionsTimeoutSettings(
            StepFunctionsTimeoutSettings::DEFAULT_LOCK_TTL,
            StepFunctionsTimeoutSettings::DEFAULT_LOCK_RELEASE_BUFFER,
            StepFunctionsTimeoutSettings::DEFAULT_MIN_TASK_TIMEOUT
        );

        $this->assertSame(3600, $settings->getLockTtlSeconds());
        $this->assertSame(60, $settings->getLockReleaseBufferSeconds());
        $this->assertSame(60, $settings->getMinTaskTimeoutSeconds());
    }

    /**
     * @testdox STS.13 deriveTimeoutSeconds returns the lock lifetime minus the buffer when dispatched on time
     */
    public function testDeriveReturnsLifetimeMinusBufferWhenDispatchedOnTime(): void
    {
        $settings = new StepFunctionsTimeoutSettings(3600, 60);

        $this->assertSame(
            3540,
            $settings->deriveTimeoutSeconds(self::DUE_AT + 3600, self::DUE_AT, 3600)
        );
    }

    /**
     * @testdox STS.14 deriveTimeoutSeconds shortens the timeout by the dispatch delay
     */
    public function testDeriveShortensByDispatchDelay(): void
    {
        $settings = new StepFunctionsTimeoutSettings(3600, 60);

        $this->assertSame(
            3378,
            $settings->deriveTimeoutSeconds(self::DUE_AT + 3600, self::DUE_AT + 162, 3600)
        );
    }

    /**
     * @testdox STS.15 A derived timeout keeps the task inside the lock lifetime
     */
    public function testDerivedTimeoutStaysInsideLockLifetime(): void
    {
        $settings = new StepFunctionsTimeoutSettings(3600, 60);

        $expiresAt = self::DUE_AT + 3600;
        $dispatchedAt = self::DUE_AT + 162;

        $timeout = $settings->deriveTimeoutSeconds($expiresAt, $dispatchedAt, 3600);

        $this->assertLessThan($expiresAt, $dispatchedAt + $timeout);
    }

    /**
     * @testdox STS.16 deriveTimeoutSeconds returns the floor exactly at the boundary
     */
    public function testDeriveReturnsFloorAtBoundary(): void
    {
        $settings = new StepFunctionsTimeoutSettings(3600, 60, 60);

        // Remaining lifetime 120 - buffer 60 = 60, exactly the floor: still tracked.
        $this->assertSame(
            60,
            $settings->deriveTimeoutSeconds(self::DUE_AT + 3600, self::DUE_AT + 3480, 3600)
        );
    }

    /**
     * @testdox STS.17 Falling one second below the floor switches to the declared lifetime
     */
    public function testDeriveFallsBackJustBelowFloor(): void
    {
        $settings = new StepFunctionsTimeoutSettings(3600, 60, 60);

        // Remaining lifetime 119 - buffer 60 = 59 -> below the floor -> declared budget.
        $this->assertSame(
            3540,
            $settings->deriveTimeoutSeconds(self::DUE_AT + 3600, self::DUE_AT + 3481, 3600)
        );
    }

    /**
     * @testdox STS.18 A recovery dispatch long after the lock expired gets the declared lifetime
     */
    public function testDeriveFallsBackForRecoveryDispatch(): void
    {
        $settings = new StepFunctionsTimeoutSettings(3600, 60, 60);

        // dailyAt('02:00') recovered six hours late: the lock is long gone, so it can no
        // longer exclude anything. Returning 1 would only kill the recovered run.
        $this->assertSame(
            3540,
            $settings->deriveTimeoutSeconds(self::DUE_AT + 3600, self::DUE_AT + 21600, 3600)
        );
    }

    /**
     * @testdox STS.19 A withoutOverlapping window narrower than the buffer gets the floor
     */
    public function testDeriveReturnsFloorForNarrowWindow(): void
    {
        $settings = new StepFunctionsTimeoutSettings(3600, 60, 60);

        // withoutOverlapping(1): the event's own lifetime is 60s, so the declared budget
        // is 0. The floor is what keeps this from becoming a 1-second timeout.
        $this->assertSame(
            60,
            $settings->deriveTimeoutSeconds(self::DUE_AT + 60, self::DUE_AT, 60)
        );
    }

    /**
     * @testdox STS.20 deriveTimeoutSeconds is always positive
     */
    public function testDeriveIsAlwaysPositive(): void
    {
        $settings = new StepFunctionsTimeoutSettings(3600, 60, 60);

        // Every shape that used to reach the max(1, ...) clamp: on time, late, far past
        // the expiry, and an event lifetime below the buffer.
        foreach ([0, 162, 3600, 86400] as $delay) {
            foreach ([30, 60, 3600] as $lockTtl) {
                $timeout = $settings->deriveTimeoutSeconds(
                    self::DUE_AT + $lockTtl,
                    self::DUE_AT + $delay,
                    $lockTtl
                );
                $this->assertGreaterThan(0, $timeout, sprintf('delay=%d lockTtl=%d', $delay, $lockTtl));
            }
        }
    }

    /**
     * @testdox STS.21 deriveTimeoutSeconds honours a custom buffer
     */
    public function testDeriveHonoursCustomBuffer(): void
    {
        $settings = new StepFunctionsTimeoutSettings(3600, 300, 60);

        $this->assertSame(
            3300,
            $settings->deriveTimeoutSeconds(self::DUE_AT + 3600, self::DUE_AT, 3600)
        );
    }

    /**
     * @testdox STS.23 A declared timeout takes precedence over the derived value
     */
    public function testDeclaredTimeoutTakesPrecedence(): void
    {
        $settings = new StepFunctionsTimeoutSettings(3600, 60, 60);

        $this->assertSame(
            1800,
            $settings->deriveTimeoutSeconds(self::DUE_AT + 3600, self::DUE_AT, 3600, 1800)
        );
    }

    /**
     * @testdox STS.24 A declared timeout is capped at what the lock can protect
     */
    public function testDeclaredTimeoutIsCappedAtLockLifetime(): void
    {
        $settings = new StepFunctionsTimeoutSettings(3600, 60, 60);

        // Declaring more than the lock can host would put the task past expiresAt, which
        // is the duplicate-execution mode. The declared value may only narrow.
        $this->assertSame(
            3540,
            $settings->deriveTimeoutSeconds(self::DUE_AT + 3600, self::DUE_AT, 3600, 7200)
        );
    }

    /**
     * @testdox STS.25 A declared timeout shrinks with the dispatch delay only when it has to
     */
    public function testDeclaredTimeoutIsCappedOnlyWhenNeeded(): void
    {
        $settings = new StepFunctionsTimeoutSettings(3600, 60, 60);

        // 1800 still fits in the remaining lifetime after a 600s delay (2940), so it stands.
        $this->assertSame(
            1800,
            $settings->deriveTimeoutSeconds(self::DUE_AT + 3600, self::DUE_AT + 600, 3600, 1800)
        );
        // After a 2400s delay only 1140 is left, so the declared value is capped.
        $this->assertSame(
            1140,
            $settings->deriveTimeoutSeconds(self::DUE_AT + 3600, self::DUE_AT + 2400, 3600, 1800)
        );
    }

    /**
     * @testdox STS.26 A declared timeout is used as is once the lock cannot host the run
     */
    public function testDeclaredTimeoutIsUsedAsIsInFallbackRegime(): void
    {
        $settings = new StepFunctionsTimeoutSettings(3600, 60, 60);

        // Recovery dispatch: no lock left to cap against, so the declared budget stands.
        $this->assertSame(
            1800,
            $settings->deriveTimeoutSeconds(self::DUE_AT + 3600, self::DUE_AT + 21600, 3600, 1800)
        );
    }

    /**
     * @testdox STS.27 The floor does not override a deliberately short declared timeout
     */
    public function testFloorDoesNotOverrideShortDeclaredTimeout(): void
    {
        $settings = new StepFunctionsTimeoutSettings(3600, 60, 60);

        // min_task_timeout guards against accidental collapse, not against an explicit choice.
        $this->assertSame(
            5,
            $settings->deriveTimeoutSeconds(self::DUE_AT + 3600, self::DUE_AT, 3600, 5)
        );
    }

    /**
     * @testdox STS.22 deriveTimeoutSeconds uses the event lifetime, not the configured fallback
     */
    public function testDeriveUsesEventLifetimeNotConfiguredFallback(): void
    {
        $settings = new StepFunctionsTimeoutSettings(3600, 60, 60);

        // withoutOverlapping(480) on an event: 28800s lifetime, config lock_ttl ignored.
        $this->assertSame(
            28740,
            $settings->deriveTimeoutSeconds(self::DUE_AT + 28800, self::DUE_AT, 28800)
        );
    }
}
