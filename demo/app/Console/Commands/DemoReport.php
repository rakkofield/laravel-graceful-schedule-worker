<?php

namespace App\Console\Commands;

use DateInterval;
use DatePeriod;
use DateTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class DemoReport extends Command
{
    /**
     * @var string
     */
    protected $signature = 'demo:report {--worker=graceful : Worker name (cron, graceful, or compare)}';

    /**
     * @var string
     */
    protected $description = 'Display tick timeline from Redis';

    /**
     * @return int
     */
    public function handle()
    {
        $worker = $this->option('worker');

        if ($worker === 'compare') {
            return $this->compareReport();
        }

        return $this->singleReport($worker);
    }

    /**
     * @param string $worker
     * @return int
     */
    private function singleReport($worker)
    {
        $prefix = "demo:tick:{$worker}";
        $first = Cache::get("{$prefix}:first");
        $last = Cache::get("{$prefix}:last");
        $count = Cache::get("{$prefix}:count", 0);

        if (!$first || !$last) {
            $this->warn("No data found for worker '{$worker}'.");
            return 1;
        }

        $this->info("Worker: {$worker}");
        $this->info("Total ticks: {$count}");
        $this->info("");

        $minutes = $this->getMinuteRange($first, $last);
        $statuses = $this->buildStatuses($prefix, $minutes);

        $headers = ['Minute', 'Status'];
        $rows = [];
        foreach ($minutes as $i => $minute) {
            $rows[] = [$minute, $statuses[$i]];
        }

        $this->table($headers, $rows);

        return 0;
    }

    /**
     * @return int
     */
    private function compareReport()
    {
        $cronFirst = Cache::get('demo:tick:cron:first');
        $cronLast = Cache::get('demo:tick:cron:last');
        $gracefulFirst = Cache::get('demo:tick:graceful:first');
        $gracefulLast = Cache::get('demo:tick:graceful:last');

        if (!$cronFirst && !$gracefulFirst) {
            $this->warn('No data found for either worker.');
            return 1;
        }

        // Determine range as union of both workers
        $allTimes = array_filter([$cronFirst, $cronLast, $gracefulFirst, $gracefulLast]);
        sort($allTimes);
        $first = reset($allTimes);
        $last = end($allTimes);

        $cronCount = Cache::get('demo:tick:cron:count', 0);
        $gracefulCount = Cache::get('demo:tick:graceful:count', 0);

        $this->info("Comparison: cron ({$cronCount} ticks) vs graceful ({$gracefulCount} ticks)");
        $this->info("");

        $minutes = $this->getMinuteRange($first, $last);
        $cronStatuses = $this->buildStatuses('demo:tick:cron', $minutes);
        $gracefulStatuses = $this->buildStatuses('demo:tick:graceful', $minutes);

        $headers = ['Minute', 'cron', 'graceful'];
        $rows = [];
        foreach ($minutes as $i => $minute) {
            $rows[] = [$minute, $cronStatuses[$i], $gracefulStatuses[$i]];
        }

        $this->table($headers, $rows);

        $diff = $gracefulCount - $cronCount;
        if ($diff > 0) {
            $this->info("graceful executed {$diff} more tick(s) than cron (recovery).");
        } elseif ($diff === 0) {
            $this->info('Both workers executed the same number of ticks.');
        } else {
            $this->info("cron executed " . abs($diff) . " more tick(s) than graceful.");
        }

        return 0;
    }

    /**
     * Threshold in seconds: execution later than this is considered recovery.
     * Normal dispatch fires at :00, recovery fires mid-minute after restart.
     */
    private static $recoveryThreshold = 5;

    /**
     * @param string $prefix
     * @param string[] $minutes
     * @return string[]
     */
    private function buildStatuses($prefix, $minutes)
    {
        $statuses = [];

        foreach ($minutes as $minute) {
            $executedAt = Cache::get("{$prefix}:timeline:{$minute}");

            if ($executedAt === null) {
                $statuses[] = 'MISSED';
            } elseif ($this->isRecovery($executedAt)) {
                $statuses[] = 'RECOVERED';
            } else {
                $statuses[] = 'OK';
            }
        }

        return $statuses;
    }

    /**
     * @param string $executedAt  e.g. "2026-02-14T10:05:32"
     * @return bool
     */
    private function isRecovery($executedAt)
    {
        $dt = new DateTime($executedAt);
        $seconds = (int) $dt->format('s');

        return $seconds > self::$recoveryThreshold;
    }

    /**
     * @param string $first
     * @param string $last
     * @return string[]
     */
    private function getMinuteRange($first, $last)
    {
        $start = new DateTime($first);
        $end = new DateTime($last);
        $end->modify('+1 minute');

        $interval = new DateInterval('PT1M');
        $period = new DatePeriod($start, $interval, $end);

        $minutes = [];
        foreach ($period as $dt) {
            $minutes[] = $dt->format('Y-m-d\TH:i');
        }

        return $minutes;
    }
}
