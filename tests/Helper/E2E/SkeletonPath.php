<?php

declare(strict_types=1);

namespace RakkoInc\LaravelGracefulScheduleWorker\E2E;

/**
 * Path of the Laravel application the E2E tests run against.
 *
 * skeleton/ is used by default. E2E_SKELETON_PATH selects another one,
 * e.g. skeleton-l11/ for Laravel 11+.
 */
final class SkeletonPath
{
    private const DEFAULT = __DIR__ . '/../../../skeleton';

    public static function get(): string
    {
        $path = getenv('E2E_SKELETON_PATH');

        return is_string($path) && $path !== '' ? $path : self::DEFAULT;
    }
}
