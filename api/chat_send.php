<?php
// Ephemeral chat send endpoint (no DB persistence)
// Stores messages in JSON files under storage/chat/ which are periodically cleaned.

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php'; // for appointment -> shop lookup when needed

header('Content-Type: application/json');
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

$user = current_user();
$role = $user['role'] ?? '';
if (!in_array($role, ['customer', 'owner'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

// Expect POST JSON: { channel: string, msg: string }
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = [];
$channel = $data['channel'] ?? '';
$msg = trim((string)($data['msg'] ?? ''));

// Allowed channel patterns:
// pre_<shopId>_<hash>  (customer <-> owner prior to booking)
// pre_all_<hash>       (generic inquiry from search page before choosing a shop)
// bk_<appointmentId>_<hash> (contextual booking thread)
// bk_<hash> legacy (from earlier implementation) still accepted
if (!preg_match('/^(pre_(?:\d+|all)_[A-Fa-f0-9]{6,40}|bk_\d+_[A-Fa-f0-9]{6,40}|bk_[A-Fa-f0-9]{6,40})$/', $channel)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_channel']);
    exit;
}

// Minimal authorization heuristics: customers can start pre_ channels; owners can reply to any; booking (bk_<id>) requires logged-in customer or owner.
if (strpos($channel, 'pre_') === 0 && $role !== 'customer' && $role !== 'owner') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'role_pre_forbidden']);
    exit;
}
if (strpos($channel, 'bk_') === 0 && !in_array($role, ['customer', 'owner'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'role_booking_forbidden']);
    exit;
}
if ($msg === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'empty']);
    exit;
}

// Rate limit basic: max 5 msgs per 10 seconds per session user
if (!isset($_SESSION['chat_rl'])) $_SESSION['chat_rl'] = [];
$now = time();
$_SESSION['chat_rl'] = array_filter($_SESSION['chat_rl'], function ($t) use ($now) {
    return ($now - $t) < 10;
});
if (count($_SESSION['chat_rl']) >= 5) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited']);
    exit;
}
$_SESSION['chat_rl'][] = $now;

$chatDir = realpath(__DIR__ . '/../storage/chat');
if (!$chatDir) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'chat_storage']);
    exit;
}
$file = $chatDir . DIRECTORY_SEPARATOR . $channel . '.json';

$payload = [
    'id' => bin2hex(random_bytes(6)),
    'ts' => $now,
    'role' => $role,
    'name' => (string)($user['full_name'] ?? ucfirst($role)),
    'msg' => mb_substr($msg, 0, 800)
];

$tries = 0;
while ($tries < 3) {
    $tries++;
    $fh = @fopen($file, 'c+');
    if (!$fh) {
        usleep(50000);
        continue;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        usleep(50000);
        continue;
    }
    $contents = stream_get_contents($fh);
    $arr = [];
    if ($contents) {
        $decoded = json_decode($contents, true);
        if (is_array($decoded)) $arr = $decoded;
    }
    // Trim to last 50 messages for memory/privacy
    if (count($arr) > 60) $arr = array_slice($arr, -50);
    $arr[] = $payload;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($arr));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    // Update per-shop index for owner visibility
    try {
        $shopIdForIndex = null;
        if (preg_match('/^pre_(\d+)_/i', $channel, $m)) {
            $shopIdForIndex = (int)$m[1];
            $entryType = 'pre';
        } elseif (preg_match('/^bk_(\d+)_/i', $channel, $m)) {
            // Appointment based channel: resolve shop id from DB
            $apptId = (int)$m[1];
            if ($apptId > 0) {
                $stmtAp = $pdo->prepare('SELECT shop_id FROM Appointments WHERE appointment_id = ?');
                if ($stmtAp->execute([$apptId])) {
                    $shopIdForIndex = (int)$stmtAp->fetchColumn();
                }
            }
            $entryType = 'appointment';
        } else {
            $entryType = 'other';
        }
        if ($shopIdForIndex) {
            $idxDir = $chatDir; // same directory
            $idxFile = $idxDir . DIRECTORY_SEPARATOR . 'index_shop_' . $shopIdForIndex . '.json';
            $list = [];
            if (is_file($idxFile)) {
                $rawIdx = @file_get_contents($idxFile);
                $decIdx = json_decode($rawIdx, true);
                if (is_array($decIdx)) $list = $decIdx;
            }
            $found = false;
            for ($i = 0; $i < count($list); $i++) {
                if (!isset($list[$i]['channel'])) continue;
                if ($list[$i]['channel'] === $channel) {
                    $list[$i]['last_ts'] = $payload['ts'];
                    $list[$i]['last_msg'] = $payload['msg'];
                    $list[$i]['last_role'] = $payload['role'];
                    $list[$i]['last_name'] = $payload['name'];
                    $list[$i]['type'] = $entryType;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $list[] = [
                    'channel' => $channel,
                    'type' => $entryType,
                    'last_ts' => $payload['ts'],
                    'last_msg' => $payload['msg'],
                    'last_role' => $payload['role'],
                    'last_name' => $payload['name']
                ];
            }
            // Keep most recent first by last_ts desc
            usort($list, function ($a, $b) {
                return ($b['last_ts'] ?? 0) <=> ($a['last_ts'] ?? 0);
            });
            if (count($list) > 80) { // trim to 80 conversations
                $list = array_slice($list, 0, 80);
            }
            @file_put_contents($idxFile, json_encode($list));
        }
    } catch (Throwable $e) {
        // Silent; index update best-effort
    }
    echo json_encode(['ok' => true, 'message' => $payload]);
    exit;
}
http_response_code(500);
echo json_encode(['ok' => false, 'error' => 'write_failed']);
