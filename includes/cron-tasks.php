<?php

namespace innovatopia_jp\editorial;

// main Cron class
class Cron {
    private static array $jobs = [];

    public static function register_job(\Closure $job) {
        $id = \spl_object_id($job);
        self::$jobs[$id] = $job;
    }

    public static function run_all_jobs() {
        foreach (self::$jobs as $_id => $job) {
            try {
                if (!is_callable($job)) continue;
                $job();
            } catch (\Throwable $e) {
                \error_log("Cron job failed: {$e->getMessage()}", \E_USER_WARNING);
            }
        }
    }
}

// Cron job definitions below

