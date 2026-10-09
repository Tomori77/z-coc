<?php

function app_rate_limit($conn, $scope, $limit, $windowSeconds) {
    $scope = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$scope);
    $limit = max(1, (int)$limit);
    $windowSeconds = max(10, (int)$windowSeconds);
    $bucket = app_client_ip() . ':' . $scope;

    app_run_periodically('api_rate_limits_schema_v1', 3600, function () use ($conn) {
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS api_rate_limits (
                bucket VARCHAR(128) NOT NULL,
                hits INTEGER NOT NULL DEFAULT 0,
                window_start TIMESTAMP NOT NULL DEFAULT NOW(),
                PRIMARY KEY (bucket)
            )");
            $conn->exec("CREATE INDEX IF NOT EXISTS idx_window_start ON api_rate_limits (window_start)");
            return true;
        } catch (Throwable $error) {
            return false;
        }
    });

    if (mt_rand(1, 100) === 1) {
        try {
            $conn->exec("DELETE FROM api_rate_limits WHERE window_start < (NOW() - INTERVAL '1 day')");
        } catch (Throwable $error) {
        }
    }

    $stmt = $conn->prepare("SELECT hits, EXTRACT(EPOCH FROM window_start) AS started_at FROM api_rate_limits WHERE bucket = ? LIMIT 1");
    $stmt->execute([$bucket]);
    $row = $stmt->fetch();
    $now = time();

    if (!$row || ($now - (int)$row['started_at']) >= $windowSeconds) {
        $hits = 1;
        $stmt = $conn->prepare("INSERT INTO api_rate_limits (bucket, hits, window_start) VALUES (?, ?, NOW()) ON CONFLICT (bucket) DO UPDATE SET hits = EXCLUDED.hits, window_start = EXCLUDED.window_start");
        $stmt->execute([$bucket, $hits]);
        return;
    }

    if ((int)$row['hits'] >= $limit) {
        header('Retry-After: ' . max(1, $windowSeconds - ($now - (int)$row['started_at'])));
        app_json_response(['status' => 'error', 'message' => 'Too many requests'], 429);
    }

    $stmt = $conn->prepare("UPDATE api_rate_limits SET hits = hits + 1 WHERE bucket = ?");
    $stmt->execute([$bucket]);
}
