<?php
require_once __DIR__ . '/api_bootstrap.php';

app_require_method('GET');

try {
    $result = app_query('SELECT 1');
} catch (Throwable $error) {
    $result = false;
}
if ($result === false) {
    app_json_response(['status' => 'error'], 503);
}

app_json_response(['status' => 'ok']);
?>
