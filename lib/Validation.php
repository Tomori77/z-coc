<?php

function app_normalize_user_id($userId) {
    $userId = trim((string)$userId);
    if ($userId === '' || strlen($userId) > 64 || !preg_match('/^[a-zA-Z0-9_-]+$/', $userId)) {
        app_json_response(['status' => 'error', 'message' => 'Invalid user id'], 400);
    }
    return $userId;
}
