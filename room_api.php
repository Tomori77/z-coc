<?php
require_once __DIR__ . '/api_bootstrap.php';

app_send_cors_headers('GET, POST, OPTIONS');

$conn = app_db();
$roomConfig = app_config()['room'] ?? [];
$roomTtlHours = min(6, max(1, (int)($roomConfig['ttl_hours'] ?? 2)));
$maxPayloadBytes = min(512 * 1024, max(16 * 1024, (int)($roomConfig['max_payload_bytes'] ?? (512 * 1024))));
$maxRoomMessages = min(500, max(50, (int)($roomConfig['max_room_messages'] ?? 240)));
$pollLimit = min(100, max(1, (int)($roomConfig['poll_limit'] ?? 80)));

function respond($data, $status = 200) {
    app_json_response($data, $status);
}

function read_json_body() {
    return app_read_json_body();
}

function normalize_room_id($roomId) {
    $roomId = strtoupper(trim((string)$roomId));
    if (!preg_match('/^[A-Z0-9_-]{6,16}$/', $roomId)) {
        respond(['status' => 'error', 'message' => 'Invalid room id'], 400);
    }
    return $roomId;
}

function normalize_sender_id($senderId) {
    $senderId = trim((string)$senderId);
    if ($senderId === '' || strlen($senderId) > 64 || !preg_match('/^[a-zA-Z0-9_-]+$/', $senderId)) {
        respond(['status' => 'error', 'message' => 'Invalid sender'], 400);
    }
    return $senderId;
}

function ensure_room_schema($conn) {
    $roomsReady = $conn->query("CREATE TABLE IF NOT EXISTS coc_rooms (
        room_id VARCHAR(32) NOT NULL,
        host_id VARCHAR(64) NOT NULL,
        host_token_hash CHAR(64) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (room_id),
        KEY idx_updated_at (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$roomsReady) return false;

    $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $table = 'coc_rooms';
    $column = 'host_token_hash';
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = app_stmt_fetch_assoc($stmt);
    if ((int)($row['n'] ?? 0) === 0) {
        if (!$conn->query("ALTER TABLE coc_rooms ADD COLUMN host_token_hash CHAR(64) NULL AFTER host_id")) {
            return false;
        }
    }

    $messagesReady = (bool)$conn->query("CREATE TABLE IF NOT EXISTS coc_room_messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        room_id VARCHAR(32) NOT NULL,
        sender_id VARCHAR(64) NOT NULL,
        payload LONGTEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_room_id_id (room_id, id),
        KEY idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$messagesReady) return false;

    // 在线状态表：玩家轮询时登记最后在线时间，供房主判定掉线/踢出
    return (bool)$conn->query("CREATE TABLE IF NOT EXISTS coc_room_presence (
        room_id VARCHAR(32) NOT NULL,
        user_id VARCHAR(64) NOT NULL,
        last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (room_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function cleanup_rooms($conn, $ttlHours) {
    $ttl = max(1, (int)$ttlHours);
    $messagesCleaned = $conn->query("DELETE m FROM coc_room_messages m LEFT JOIN coc_rooms r ON r.room_id = m.room_id WHERE r.room_id IS NULL OR m.created_at < (NOW() - INTERVAL {$ttl} HOUR)");
    $roomsCleaned = $conn->query("DELETE FROM coc_rooms WHERE updated_at < (NOW() - INTERVAL {$ttl} HOUR)");
    $presenceCleaned = $conn->query("DELETE p FROM coc_room_presence p LEFT JOIN coc_rooms r ON r.room_id = p.room_id WHERE r.room_id IS NULL");
    return $messagesCleaned !== false && $roomsCleaned !== false && $presenceCleaned !== false;
}

function prune_room_messages($conn, $roomId, $maxMessages) {
    $maxMessages = min(500, max(50, (int)$maxMessages));
    $stmt = $conn->prepare("SELECT id FROM coc_room_messages WHERE room_id = ? ORDER BY id DESC LIMIT 1 OFFSET {$maxMessages}");
    $stmt->bind_param('s', $roomId);
    $stmt->execute();
    $row = app_stmt_fetch_assoc($stmt);
    if (!$row) return;

    $cutoffId = (int)$row['id'];
    $stmt = $conn->prepare("DELETE FROM coc_room_messages WHERE room_id = ? AND id <= ?");
    $stmt->bind_param('si', $roomId, $cutoffId);
    $stmt->execute();
}

function get_room($conn, $roomId, $ttlHours) {
    $ttl = max(1, (int)$ttlHours);
    $stmt = $conn->prepare("SELECT room_id, host_id, host_token_hash FROM coc_rooms WHERE room_id = ? AND updated_at >= (NOW() - INTERVAL {$ttl} HOUR) LIMIT 1");
    $stmt->bind_param('s', $roomId);
    $stmt->execute();
    $room = app_stmt_fetch_assoc($stmt);
    if (!$room) {
        respond(['status' => 'error', 'message' => 'Room not found or expired'], 404);
    }
    return $room;
}

function touch_room($conn, $roomId) {
    $stmt = $conn->prepare("UPDATE coc_rooms SET updated_at = NOW() WHERE room_id = ? AND updated_at < (NOW() - INTERVAL 30 SECOND)");
    $stmt->bind_param('s', $roomId);
    $stmt->execute();
}

function is_host_only_message($type) {
    static $hostOnlyTypes = [
        'sync_state' => true,
        'sync_log_append' => true,
        'update_last_message' => true,
        'update_last_message_full' => true,
        'sync_module' => true,
        'sync_investigators' => true,
        'sync_assignments' => true,
        'game_start' => true,
        'game_ended' => true,
        'room_closed' => true,
        'grant_cot_access' => true,
        'coj_toggle' => true,
        'generating_start' => true,
        'generating_end' => true,
        'regenerate_start' => true,
        'distribute_reports' => true,
        'kick_player' => true,
        'move_to_spectator' => true,
    ];
    return isset($hostOnlyTypes[$type]);
}

function is_player_log_append($payload, $senderId) {
    if (($payload['type'] ?? '') !== 'sync_log_append') return false;
    $message = $payload['message'] ?? null;
    if (!is_array($message)) return false;

    $role = (string)($message['role'] ?? '');
    if ($role !== 'user' && $role !== 'whisper') return false;
    if (!empty($message['isAiGenerated'])) return false;

    $claimedSender = $message['senderId'] ?? ($message['id'] ?? null);
    return $claimedSender !== null && (string)$claimedSender === $senderId;
}

app_run_periodically('room_schema_v2', 3600, function () use ($conn) {
    return ensure_room_schema($conn);
});
app_run_periodically('room_cleanup_' . $roomTtlHours, 300, function () use ($conn, $roomTtlHours) {
    return cleanup_rooms($conn, $roomTtlHours);
});

$action = $_GET['action'] ?? '';

if ($action === 'create') {
    app_require_method('POST');
    app_rate_limit($conn, 'room_create', 30, 300);
    app_require_content_length(64 * 1024);
    $data = read_json_body();
    $roomId = normalize_room_id($data['room_id'] ?? '');
    $hostId = normalize_sender_id($data['user_id'] ?? '');
    $hostToken = bin2hex(random_bytes(32));
    $hostTokenHash = hash('sha256', $hostToken);

    $stmt = $conn->prepare("SELECT room_id FROM coc_rooms WHERE room_id = ? AND updated_at >= (NOW() - INTERVAL {$roomTtlHours} HOUR) LIMIT 1");
    $stmt->bind_param('s', $roomId);
    $stmt->execute();
    if (app_stmt_fetch_assoc($stmt)) {
        respond(['status' => 'error', 'message' => 'Room already exists'], 409);
    }

    $stmt = $conn->prepare("REPLACE INTO coc_rooms (room_id, host_id, host_token_hash, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
    $stmt->bind_param('sss', $roomId, $hostId, $hostTokenHash);
    if (!$stmt->execute()) respond(['status' => 'error', 'message' => 'Room create failed'], 500);

    $stmt = $conn->prepare("DELETE FROM coc_room_messages WHERE room_id = ?");
    $stmt->bind_param('s', $roomId);
    if (!$stmt->execute()) respond(['status' => 'error', 'message' => 'Room reset failed'], 500);

    respond(['status' => 'success', 'last_id' => 0, 'host_token' => $hostToken]);
}

if ($action === 'join') {
    app_require_method('POST');
    app_rate_limit($conn, 'room_join', 120, 300);
    app_require_content_length(64 * 1024);
    $data = read_json_body();
    $roomId = normalize_room_id($data['room_id'] ?? '');
    get_room($conn, $roomId, $roomTtlHours);
    touch_room($conn, $roomId);

    $stmt = $conn->prepare("SELECT COALESCE(MAX(id), 0) AS last_id FROM coc_room_messages WHERE room_id = ?");
    $stmt->bind_param('s', $roomId);
    $stmt->execute();
    $row = app_stmt_fetch_assoc($stmt);

    respond(['status' => 'success', 'last_id' => (int)($row['last_id'] ?? 0)]);
}

if ($action === 'push') {
    app_require_method('POST');
    app_rate_limit($conn, 'room_push', 600, 300);
    app_require_content_length($maxPayloadBytes + 32768);
    $data = read_json_body();
    $roomId = normalize_room_id($data['room_id'] ?? '');
    $senderId = normalize_sender_id($data['sender_id'] ?? '');
    $room = get_room($conn, $roomId, $roomTtlHours);

    if (!array_key_exists('payload', $data) || !is_array($data['payload'])) {
        respond(['status' => 'error', 'message' => 'Missing payload'], 400);
    }

    $payload = $data['payload'];
    $type = (string)($payload['type'] ?? '');
    if ($type === '' || strlen($type) > 64) {
        respond(['status' => 'error', 'message' => 'Invalid message type'], 400);
    }

    $isPlayerLogAppend = is_player_log_append($payload, $senderId);

    if (is_host_only_message($type) && !$isPlayerLogAppend) {
        $hostToken = trim((string)($data['host_token'] ?? ''));
        $hostTokenHash = hash('sha256', $hostToken);
        if ($senderId !== $room['host_id'] || $hostToken === '' || !hash_equals((string)$room['host_token_hash'], $hostTokenHash)) {
            respond(['status' => 'error', 'code' => 'HOST_AUTH_FAILED', 'message' => 'Only host can send this message'], 403);
        }
    }

    if (!is_host_only_message($type) || $isPlayerLogAppend) {
        $claimedUserId = $payload['user']['id'] ?? ($payload['userId'] ?? null);
        if ($isPlayerLogAppend) {
            $claimedUserId = $payload['message']['senderId'] ?? ($payload['message']['id'] ?? null);
        }
        if ($claimedUserId !== null && (string)$claimedUserId !== $senderId) {
            respond(['status' => 'error', 'message' => 'Sender mismatch'], 403);
        }
    }

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($payloadJson === false) respond(['status' => 'error', 'message' => 'Payload encode failed'], 400);
    if (strlen($payloadJson) > $maxPayloadBytes) {
        respond(['status' => 'error', 'message' => 'Payload too large'], 413);
    }

    $stmt = $conn->prepare("INSERT INTO coc_room_messages (room_id, sender_id, payload) VALUES (?, ?, ?)");
    $stmt->bind_param('sss', $roomId, $senderId, $payloadJson);
    if (!$stmt->execute()) respond(['status' => 'error', 'message' => 'Message send failed'], 500);
    prune_room_messages($conn, $roomId, $maxRoomMessages);
    touch_room($conn, $roomId);

    respond(['status' => 'success', 'id' => (int)$stmt->insert_id]);
}

if ($action === 'pull') {
    app_require_method('GET');
    app_rate_limit($conn, 'room_pull', 900, 300);
    $roomId = normalize_room_id($_GET['room_id'] ?? '');
    get_room($conn, $roomId, $roomTtlHours);
    $since = max(0, (int)($_GET['since'] ?? 0));
    $messages = [];

    $stmt = $conn->prepare("SELECT id, sender_id, payload FROM coc_room_messages WHERE room_id = ? AND id > ? ORDER BY id ASC LIMIT {$pollLimit}");
    $stmt->bind_param('si', $roomId, $since);
    $stmt->execute();

    foreach (app_stmt_fetch_all_assoc($stmt) as $row) {
        $messages[] = [
            'id' => (int)$row['id'],
            'sender_id' => $row['sender_id'],
            'payload' => json_decode($row['payload'], true),
        ];
    }

    // 在线状态：登记本次轮询者，并返回房间内全部成员的最后在线时间（供房主判定掉线）
    $presenceUser = trim((string)($_GET['user_id'] ?? ''));
    if ($presenceUser !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $presenceUser) && strlen($presenceUser) <= 64) {
        $stmt = $conn->prepare("REPLACE INTO coc_room_presence (room_id, user_id, last_seen) VALUES (?, ?, NOW())");
        $stmt->bind_param('ss', $roomId, $presenceUser);
        $stmt->execute();
    }

    $presence = [];
    $stmt = $conn->prepare("SELECT user_id, UNIX_TIMESTAMP(last_seen) AS seen_at FROM coc_room_presence WHERE room_id = ?");
    $stmt->bind_param('s', $roomId);
    $stmt->execute();
    foreach (app_stmt_fetch_all_assoc($stmt) as $row) {
        $presence[] = ['user_id' => $row['user_id'], 'seen_at' => (int)$row['seen_at']];
    }

    touch_room($conn, $roomId);

    respond(['status' => 'success', 'messages' => $messages, 'presence' => $presence, 'now' => time()]);
}

respond(['status' => 'error', 'message' => 'Unknown action'], 404);
?>
