<?php

function app_db() {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $db = app_config()['db'] ?? [];
    foreach (['host', 'user', 'database'] as $key) {
        if (empty($db[$key]) || strpos((string)$db[$key], 'your_database_') === 0) {
            app_json_response(['status' => 'error', 'message' => 'Database is not configured'], 500);
        }
    }

    $port = filter_var($db['port'] ?? 5432, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]);
    if ($port === false) {
        app_json_response(['status' => 'error', 'message' => 'Database port is invalid'], 500);
    }

    $driver = (string)($db['driver'] ?? 'pgsql');
    if ($driver === '') $driver = 'pgsql';
    $dsn = $driver . ':host=' . $db['host'] . ';port=' . (int)$port . ';dbname=' . $db['database'];

    try {
        $pdo = new PDO($dsn, (string)$db['user'], (string)($db['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Keep TIMESTAMP columns aligned with PHP's UTC epoch (time()).
        $pdo->exec("SET TIME ZONE 'UTC'");
    } catch (Throwable $error) {
        app_json_response(['status' => 'error', 'message' => 'Database connection failed'], 500);
    }

    return $pdo;
}

function app_query($sql, $params = []) {
    $stmt = app_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function app_fetch_one($sql, $params = []) {
    $stmt = app_query($sql, $params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function app_fetch_all($sql, $params = []) {
    $stmt = app_query($sql, $params);
    return $stmt->fetchAll();
}

function app_execute($sql, $params = []) {
    $stmt = app_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function app_is_unique_violation($error) {
    if (!($error instanceof PDOException)) return false;
    $sqlState = (string)$error->getCode();
    if ($sqlState === '23505') return true;
    if (isset($error->errorInfo[0]) && (string)$error->errorInfo[0] === '23505') return true;
    return false;
}
