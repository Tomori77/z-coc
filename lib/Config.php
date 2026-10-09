<?php

function app_first_env($names) {
    foreach ((array)$names as $name) {
        $value = getenv((string)$name);
        if ($value !== false && $value !== '') return $value;
    }
    return null;
}

function app_parse_database_url($url) {
    $url = trim((string)$url);
    if ($url === '') return [];

    $parts = parse_url($url);
    if (!is_array($parts)) return [];

    $parsed = [];
    if (!empty($parts['scheme'])) {
        $scheme = strtolower((string)$parts['scheme']);
        $parsed['driver'] = ($scheme === 'postgres' || $scheme === 'postgresql' || $scheme === 'pgsql') ? 'pgsql' : $scheme;
    }
    if (isset($parts['host']) && $parts['host'] !== '') $parsed['host'] = $parts['host'];
    if (isset($parts['port'])) $parsed['port'] = (string)$parts['port'];
    if (isset($parts['user'])) $parsed['user'] = rawurldecode($parts['user']);
    if (isset($parts['pass'])) $parsed['password'] = rawurldecode($parts['pass']);
    if (isset($parts['path'])) {
        $database = ltrim($parts['path'], '/');
        if ($database !== '') $parsed['database'] = rawurldecode($database);
    }
    return $parsed;
}

function app_config() {
    static $config = null;
    if ($config !== null) return $config;

    $examplePath = __DIR__ . '/../config.example.php';
    $localPath = __DIR__ . '/../config.local.php';
    $config = file_exists($examplePath) ? require $examplePath : [];

    if (file_exists($localPath)) {
        $localConfig = require $localPath;
        if (is_array($localConfig)) {
            $config = array_replace_recursive($config, $localConfig);
        }
    }

    // Production platforms such as Zeabur inject PostgreSQL credentials as
    // environment variables. Environment values take precedence over files so
    // secrets never need to be committed or baked into the container image.
    $environmentDb = [
        'host' => app_first_env(['PGSQL_HOST', 'DB_HOST']),
        'port' => app_first_env(['PGSQL_PORT', 'DB_PORT']),
        'user' => app_first_env(['PGSQL_USERNAME', 'PGSQL_USER', 'DB_USER']),
        'password' => app_first_env(['PGSQL_PASSWORD', 'DB_PASSWORD']),
        'database' => app_first_env(['PGSQL_DATABASE', 'DB_NAME', 'DB_DATABASE']),
    ];

    $databaseUrl = app_first_env(['DATABASE_URL']);
    $urlDb = $databaseUrl !== null ? app_parse_database_url($databaseUrl) : [];

    foreach ($environmentDb as $key => $value) {
        if ($value !== null) {
            $config['db'][$key] = $value;
        } elseif (isset($urlDb[$key]) && $urlDb[$key] !== '') {
            $config['db'][$key] = $urlDb[$key];
        }
    }
    if (!empty($urlDb['driver'])) {
        $config['db']['driver'] = $urlDb['driver'];
    }

    $allowedOrigins = getenv('APP_ALLOWED_ORIGINS');
    if ($allowedOrigins !== false) {
        $config['allowed_origins'] = array_values(array_filter(array_map(
            'trim',
            explode(',', $allowedOrigins)
        )));
    }

    return is_array($config) ? $config : [];
}
