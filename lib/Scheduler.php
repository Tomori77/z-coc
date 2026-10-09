<?php

function app_run_periodically($key, $intervalSeconds, $callback) {
    static $completed = [];
    $intervalSeconds = max(10, (int)$intervalSeconds);
    $db = app_config()['db'] ?? [];
    $identity = implode('|', [
        (string)($db['host'] ?? ''),
        (string)($db['database'] ?? ''),
        (string)$key,
    ]);
    $cacheKey = 'z_coc_maintenance_' . hash('sha256', $identity);

    if (isset($completed[$cacheKey])) return false;

    if (function_exists('apcu_fetch')) {
        $cached = apcu_fetch($cacheKey, $hit);
        if ($hit && $cached) {
            $completed[$cacheKey] = true;
            return false;
        }
    }

    $markerPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . $cacheKey . '.stamp';
    $handle = @fopen($markerPath, 'c+');
    if ($handle && @flock($handle, LOCK_EX)) {
        rewind($handle);
        $lastRun = (int)trim((string)stream_get_contents($handle));
        if ($lastRun > 0 && (time() - $lastRun) < $intervalSeconds) {
            flock($handle, LOCK_UN);
            fclose($handle);
            $completed[$cacheKey] = true;
            if (function_exists('apcu_store')) apcu_store($cacheKey, 1, $intervalSeconds);
            return false;
        }

        $succeeded = call_user_func($callback) !== false;
        if ($succeeded) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string)time());
            fflush($handle);
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    } else {
        if ($handle) fclose($handle);
        $succeeded = call_user_func($callback) !== false;
    }

    if (!empty($succeeded)) {
        $completed[$cacheKey] = true;
        if (function_exists('apcu_store')) apcu_store($cacheKey, 1, $intervalSeconds);
        return true;
    }
    return false;
}
