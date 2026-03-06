<?php
header('Content-Type: application/json; charset=utf-8');
// 30-minute inactivity timeout (in seconds)
$SESSION_TIMEOUT = 30 * 60;
ini_set('session.gc_maxlifetime', (string)$SESSION_TIMEOUT);
session_set_cookie_params([
    'lifetime' => $SESSION_TIMEOUT,
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
// enforce inactivity timeout
if (!empty($_SESSION['LAST_ACTIVITY']) && (time() - intval($_SESSION['LAST_ACTIVITY']) > $SESSION_TIMEOUT)) {
    session_unset();
    session_destroy();
    session_start();
    $_SESSION = [];
    $_SESSION['timed_out'] = true;
}
$_SESSION['LAST_ACTIVITY'] = time();
require_once 'config.php';

try {
    // get Authorization Bearer token if present
    $authHeader = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        if (!empty($hdrs['Authorization'])) $authHeader = trim($hdrs['Authorization']);
        elseif (!empty($hdrs['authorization'])) $authHeader = trim($hdrs['authorization']);
    }
    $bearerToken = '';
    if ($authHeader && preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
        $bearerToken = $m[1];
    }

    // read scope and optional revoke_user_id from POST or raw JSON
    $scope = '';
    $revokeUserId = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $scope = isset($_POST['scope']) ? trim((string)$_POST['scope']) : '';
        if ($scope === '') {
            $raw = file_get_contents('php://input');
            if ($raw) {
                $j = json_decode($raw, true);
                if (is_array($j)) {
                    if (!empty($j['scope'])) $scope = trim((string)$j['scope']);
                    if (!empty($j['revoke_user_id'])) $revokeUserId = intval($j['revoke_user_id']);
                }
            }
        } else {
            if (isset($_POST['revoke_user_id'])) $revokeUserId = intval($_POST['revoke_user_id']);
        }
    }

    // If admin requests revoke for a specific user (e.g. when locking account),
    // revoke all tokens for that user.
    if ($revokeUserId) {
        $u = $conn->prepare("UPDATE auth_tokens SET revoked = 1 WHERE user_id = ?");
        if ($u) {
            $u->bind_param("i", $revokeUserId);
            $u->execute();
            $affected = $u->affected_rows;
            $u->close();
            echo json_encode(['success' => true, 'message' => 'Revoked tokens for user', 'revoked_count' => $affected]);
            exit;
        } else {
            // fallback simple query
            $conn->query("UPDATE auth_tokens SET revoked = 1 WHERE user_id = ".intval($revokeUserId));
            echo json_encode(['success' => true, 'message' => 'Revoked tokens for user (fallback)']);
            exit;
        }
    }

    if ($scope === 'all') {
        // full logout: destroy entire session (legacy behavior)
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_unset();
        session_destroy();

        echo json_encode(['success' => true, 'message' => 'Logged out (global)']);
        exit;
    } else {
        // scoped/tab logout:
        // 1) if bearer token provided, mark that token revoked in DB
        if ($bearerToken) {
            $q = $conn->prepare("UPDATE auth_tokens SET revoked = 1 WHERE token = ? LIMIT 1");
            if ($q) {
                $q->bind_param("s", $bearerToken);
                $q->execute();
                $affected = $q->affected_rows;
                $q->close();
                // also remove from session fallback
                if (isset($_SESSION['auth_tokens'][$bearerToken])) unset($_SESSION['auth_tokens'][$bearerToken]);
                echo json_encode(['success' => true, 'message' => 'Logged out (token)', 'revoked' => $affected > 0]);
                exit;
            } else {
                // fallback: attempt simple query
                $conn->query("UPDATE auth_tokens SET revoked = 1 WHERE token = '".$conn->real_escape_string($bearerToken)."' LIMIT 1");
                if (isset($_SESSION['auth_tokens'][$bearerToken])) unset($_SESSION['auth_tokens'][$bearerToken]);
                echo json_encode(['success' => true, 'message' => 'Logged out (token fallback)']);
                exit;
            }
        }

        // 2) fallback: remove common auth keys (for older clients that didn't use token)
        $keys = ['user_id','fullname','email','role','show_welcome'];
        foreach ($keys as $k) {
            if (isset($_SESSION[$k])) unset($_SESSION[$k]);
        }
        echo json_encode(['success' => true, 'message' => 'Logged out (local)']);
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
    exit;
}
?>
