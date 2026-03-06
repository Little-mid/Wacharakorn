<?php
header('Content-Type: application/json; charset=utf-8');
// Prevent sensitive pages/data from being cached by the browser/back-forward cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

try {
    // Inactivity timeout (seconds)
    $SESSION_TIMEOUT = 1 * 60; // 30 minutes

    ini_set('session.gc_maxlifetime', (string)$SESSION_TIMEOUT);

    // Detect HTTPS (also supports reverse proxies)
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    // Use session cookie (lifetime 0) + HttpOnly + SameSite
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
// enforce inactivity timeout
    if (!empty($_SESSION['LAST_ACTIVITY']) && (time() - intval($_SESSION['LAST_ACTIVITY']) > $SESSION_TIMEOUT)) {
        // explicitly destroy session and inform client that session timed out
        session_unset();
        session_destroy();
        echo json_encode(['success' => false, 'timed_out' => true, 'message' => 'session_timed_out']);
        exit;
    }
    $_SESSION['LAST_ACTIVITY'] = time();
    require_once 'config.php'; // เพื่อใช้ $conn

    // helper: read Bearer token from Authorization header
    $token = '';
    $authHeader = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        if (!empty($hdrs['Authorization'])) $authHeader = trim($hdrs['Authorization']);
        elseif (!empty($hdrs['authorization'])) $authHeader = trim($hdrs['authorization']);
    }
    if ($authHeader && preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
        $token = $m[1];
    }

    // If token provided, validate against DB auth_tokens
    if ($token) {
        $q = $conn->prepare("SELECT user_id, role, show_welcome, revoked FROM auth_tokens WHERE token = ? LIMIT 1");
        if ($q) {
            $q->bind_param("s", $token);
            $q->execute();
            $res = $q->get_result();
            if ($res && $res->num_rows === 1) {
                $row = $res->fetch_assoc();
                if (!empty($row['revoked'])) {
                    echo json_encode(['success'=>false,'message'=>'Token revoked']);
                    exit;
                }
                $uid = intval($row['user_id']);
                $role = $row['role'] ?? '';
                $show = !empty($row['show_welcome']) ? true : false;

                // fetch user info from appropriate table (try the expected table first)
                $u = null;
                if ($role === 'staff') {
                    // ADD role column in select so we can prefer DB-stored role (super_admin)
                    $tstmt = $conn->prepare("SELECT nurse_id AS id, fullname, email, role FROM nurses WHERE nurse_id = ? LIMIT 1");
                } else {
                    $tstmt = $conn->prepare("SELECT user_id AS id, fullname, email, role FROM users WHERE user_id = ? LIMIT 1");
                }
                if ($tstmt) {
                    $tstmt->bind_param("i", $uid);
                    $tstmt->execute();
                    $tres = $tstmt->get_result();
                    if ($tres && $tres->num_rows === 1) {
                        $u = $tres->fetch_assoc();
                        // If DB record explicitly contains a role (e.g. 'super_admin'), prefer that for UI
                        if (!empty($u['role'])) {
                            $role = $u['role'];
                        }
                    }
                    $tstmt->close();
                }

                // if not found in expected table, try the other table (helps when role in token is inconsistent)
                if (!$u) {
                    $altStmt = $conn->prepare("SELECT nurse_id AS id, fullname, email, role FROM nurses WHERE nurse_id = ? LIMIT 1");
                    if ($altStmt) {
                        $altStmt->bind_param("i", $uid);
                        $altStmt->execute();
                        $ares = $altStmt->get_result();
                        if ($ares && $ares->num_rows === 1) {
                            $u = $ares->fetch_assoc();
                            $role = $u['role'] ?? 'staff';
                        }
                        $altStmt->close();
                    }
                }
                if (!$u) {
                    $altStmt2 = $conn->prepare("SELECT user_id AS id, fullname, email, role FROM users WHERE user_id = ? LIMIT 1");
                    if ($altStmt2) {
                        $altStmt2->bind_param("i", $uid);
                        $altStmt2->execute();
                        $ares2 = $altStmt2->get_result();
                        if ($ares2 && $ares2->num_rows === 1) {
                            $u = $ares2->fetch_assoc();
                            $role = $u['role'] ?? 'admin';
                        }
                        $altStmt2->close();
                    }
                }

                if ($u) {
                    // clear token-level show_welcome so it's one-time
                    $u2 = $conn->prepare("UPDATE auth_tokens SET show_welcome = 0 WHERE token = ? LIMIT 1");
                    if ($u2) { $u2->bind_param("s", $token); $u2->execute(); $u2->close(); }

                    // prefer explicit mapping in roles_map (loginname) if present
                    $login = $u['email'] ?? '';
                    if ($login) {
                        // case-insensitive lookup so mapping works regardless of email case
                        $rm = $conn->prepare("SELECT role FROM roles_map WHERE LOWER(loginname) = LOWER(?) LIMIT 1");
                        if ($rm) {
                            $rm->bind_param("s", $login);
                            $rm->execute();
                            $rres = $rm->get_result();
                            if ($rres && $rres->num_rows === 1) {
                                $rrow = $rres->fetch_assoc();
                                if (!empty($rrow['role'])) $role = $rrow['role'];
                            }
                            $rm->close();
                        }
                    }

                    echo json_encode([
                        'success'=>true,
                        'user_id'=>intval($u['id']),
                        'fullname'=>$u['fullname'] ?? '',
                        'email'=>$u['email'] ?? '',
                        'role'=>strtolower(trim($role)),
                        'show_welcome'=>$show
                    ]);
                    exit;
                }

                // If token exists in DB but no corresponding user row found (common for HOSxP-based logins
                // where we insert auth_tokens with user_id = 0), return a minimal success response using
                // the token's role so client-side UI can proceed. Full user details may be empty.
                echo json_encode([
                    'success' => true,
                    'user_id' => intval($uid),
                    'fullname' => '',
                    'email' => '',
                    'role' => $role,
                    'show_welcome' => $show,
                    'note' => 'user-row-not-found'
                ]);
                exit;
            }
            $q->close();
        }
        // token not found in DB => treat as unauthenticated
        echo json_encode(['success'=>false,'message'=>'ผู้ใช้งานยังไม่เข้าสู่ระบบ']);
        exit;
    }

    // Fallback: legacy cookie/session-based auth
    if (isset($_SESSION['user_id']) && isset($_SESSION['fullname'])) {
        $show = false;
        if (!empty($_SESSION['show_welcome'])) {
            $show = true;
            unset($_SESSION['show_welcome']);
        }

        // prefer explicit mapping in roles_map if session email exists (case-insensitive)
        $sessRole = $_SESSION['role'] ?? '';
        $sessEmail = $_SESSION['email'] ?? '';
        if ($sessEmail) {
            $rm = $conn->prepare("SELECT role FROM roles_map WHERE LOWER(loginname) = LOWER(?) LIMIT 1");
            if ($rm) {
                $rm->bind_param("s", $sessEmail);
                $rm->execute();
                $rres = $rm->get_result();
                if ($rres && $rres->num_rows === 1) {
                    $rrow = $rres->fetch_assoc();
                    if (!empty($rrow['role'])) $sessRole = $rrow['role'];
                }
                $rm->close();
            }
        }

        echo json_encode([
            'success' => true,
            'user_id' => intval($_SESSION['user_id']),
            'fullname' => $_SESSION['fullname'],
            'email' => $sessEmail,
            'role' => strtolower(trim($sessRole)),
            'show_welcome' => $show
        ]);
        exit;
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'ผู้ใช้งานยังไม่เข้าสู่ระบบ'
        ]);
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ]);
}
?>
