<?php

function app_send_cors_headers($methods = 'GET, POST, OPTIONS') {
    $config = app_config();
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedOrigins = $config['allowed_origins'] ?? [];

    if ($origin !== '') {
        header('Vary: Origin');
        if (in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        }
    }

    header('Access-Control-Allow-Methods: ' . $methods);
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 600');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function app_json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function app_read_json_body() {
    $maxBytes = max(1024, (int)($GLOBALS['app_max_request_bytes'] ?? (1024 * 1024)));
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw !== false && strlen($raw) > $maxBytes) {
        app_json_response(['status' => 'error', 'message' => 'Request body too large'], 413);
    }
    if ($raw === false || trim($raw) === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        app_json_response(['status' => 'error', 'message' => 'Invalid JSON'], 400);
    }
    return $data;
}

function app_require_content_length($maxBytes) {
    $maxBytes = max(1, (int)$maxBytes);
    $GLOBALS['app_max_request_bytes'] = $maxBytes;
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > $maxBytes) {
        app_json_response(['status' => 'error', 'message' => 'Request body too large'], 413);
    }
}

function app_require_method($method) {
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        app_json_response(['status' => 'error', 'message' => 'Method not allowed'], 405);
    }
}

function app_get_bearer_token() {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$auth && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'authorization') {
                $auth = $value;
                break;
            }
        }
    }

    if (preg_match('/Bearer\s+(.+)/i', $auth, $matches)) {
        return trim($matches[1]);
    }
    return '';
}

function app_client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr($ip, 0, 45);
}
