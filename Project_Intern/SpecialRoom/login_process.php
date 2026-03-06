<?php
// --- SAFETY: Force JSON-only output (prevents stray warnings/HTML breaking fetch JSON.parse) ---
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@error_reporting(0);
if (function_exists('mysqli_report')) { @mysqli_report(MYSQLI_REPORT_OFF); }
if (function_exists('ob_start')) { @ob_start(); }

header('Content-Type: application/json; charset=utf-8');
// Prevent caching of auth responses
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once 'config.php';
require_once 'db_hosxp.php';
// ensure mysqli doesn't throw exceptions (some configs enable STRICT reporting)
if (function_exists('mysqli_report')) { @mysqli_report(MYSQLI_REPORT_OFF); }


// Inactivity timeout (seconds)
$SESSION_TIMEOUT = 30; // 30 minutes
ini_set('session.gc_maxlifetime', (string)$SESSION_TIMEOUT);

// Detect HTTPS (also supports reverse proxies)
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();



// Resolve role from local DB (users.role or roles_map.role) using loginname/email.
// Returns 'staff' | 'admin' | 'super_admin' | null
function resolveRoleFromDb($conn, $loginname)
{
    $loginname = trim((string)$loginname);
    if ($loginname === '') return null;

    // 1) Prefer users.role
    $stmt = $conn->prepare("SELECT role FROM users WHERE email = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $loginname);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $stmt->close();
            $r = isset($row['role']) ? strtolower(trim((string)$row['role'])) : null;
            if ($r) return $r;
        } else {
            $stmt->close();
        }
    }

    // 2) Fallback roles_map.role (for users not yet synced into users table)
    $stmt2 = $conn->prepare("SELECT role FROM roles_map WHERE loginname = ? LIMIT 1");
    if ($stmt2) {
        $stmt2->bind_param('s', $loginname);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        if ($res2 && $res2->num_rows === 1) {
            $row2 = $res2->fetch_assoc();
            $stmt2->close();
            $r2 = isset($row2['role']) ? strtolower(trim((string)$row2['role'])) : null;
            if ($r2) return $r2;
        } else {
            $stmt2->close();
        }
    }

    return null;
}

// enforce inactivity timeout
if (!empty($_SESSION['LAST_ACTIVITY']) && (time() - intval($_SESSION['LAST_ACTIVITY']) > $SESSION_TIMEOUT)) {
    session_unset();
    session_destroy();
    session_start();
    $_SESSION = [];
    // mark timed_out for possible use (login endpoint normally used on login page)
    $_SESSION['timed_out'] = true;
}
$_SESSION['LAST_ACTIVITY'] = time();

$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
// === LOGIN RATE LIMIT (simple, in-session; low risk to existing system) ===
// Block after 10 failed attempts within 10 minutes per IP.
if (!isset($_SESSION['LOGIN_FAIL']) || !is_array($_SESSION['LOGIN_FAIL'])) {
    $_SESSION['LOGIN_FAIL'] = [];
}
if (!isset($_SESSION['LOGIN_FAIL'][$clientIp])) {
    $_SESSION['LOGIN_FAIL'][$clientIp] = ['count' => 0, 'first_ts' => time()];
} else {
    // reset window if older than 10 minutes
    if (time() - intval($_SESSION['LOGIN_FAIL'][$clientIp]['first_ts'] ?? 0) > 600) {
        $_SESSION['LOGIN_FAIL'][$clientIp] = ['count' => 0, 'first_ts' => time()];
    }
}
// helper to record a failed login attempt
$session =& $_SESSION;
$recordLoginFailure = function() use (&$session, $clientIp) {
    if (!isset($session['LOGIN_FAIL'][$clientIp])) {
        $session['LOGIN_FAIL'][$clientIp] = ['count' => 1, 'first_ts' => time()];
        return;
    }
    // reset window if needed
    if (time() - intval($session['LOGIN_FAIL'][$clientIp]['first_ts'] ?? 0) > 600) {
        $session['LOGIN_FAIL'][$clientIp] = ['count' => 1, 'first_ts' => time()];
        return;
    }
    $session['LOGIN_FAIL'][$clientIp]['count'] = intval($session['LOGIN_FAIL'][$clientIp]['count'] ?? 0) + 1;
};





if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Enforce rate limit before any DB work
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $lf = $_SESSION['LOGIN_FAIL'][$clientIp] ?? null;
    if ($lf && intval($lf['count'] ?? 0) >= 10 && (time() - intval($lf['first_ts'] ?? 0) <= 600)) {
        if (function_exists('ob_get_length') && ob_get_length()) { @ob_clean(); }

        echo json_encode(['success' => false, 'message' => 'พยายามเข้าสู่ระบบมากเกินไป กรุณารอ 10 นาทีแล้วลองใหม่']);
        exit;
    }

    // Preserve exact input including leading/trailing whitespace and special characters
    $email = isset($_POST['email']) ? (string)$_POST['email'] : '';
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
    // remember the originally posted role (which form the user used)
    $postedRole = isset($_POST['role']) ? trim((string)$_POST['role']) : '';
    // Ignore UI role; resolve role from users.role after authentication
    $role = 'staff';

    // treat posted email as loginname when authenticating against HOSxP
    $loginname = $email;

    // 1) Try HOSxP authentication first (opduser table)
    $hosxpUser = null;
    if (isset($hosxpConn) && $hosxpConn) {
        $hsql = "SELECT loginname, passweb, name, account_disable FROM opduser WHERE BINARY loginname = ? LIMIT 1";
        $hst = $hosxpConn->prepare($hsql);
        if ($hst) {
            $hst->bind_param('s', $loginname);
            $hst->execute();
            $hres = $hst->get_result();
            if ($hres && $hres->num_rows > 0) {
                $hosxpUser = $hres->fetch_assoc();
            }
            $hst->close();
        }
    }

    if ($hosxpUser) {
        $passweb = $hosxpUser['passweb'] ?? '';
        $fullname = $hosxpUser['name'] ?? $loginname;
        $ok = false;
        if ($passweb !== '') {
        $accDisable = strtoupper(trim($hosxpUser['account_disable'] ?? ''));
        if ($accDisable !== 'N') {
            echo json_encode([
                "success" => false,
                "message" => "บัญชีนี้ถูกปิดการใช้งาน กรุณาติดต่อผู้ดูแลระบบ IT"
            ]);
            exit;
        }

            $pw = (string)$password;
            $ph = trim((string)$passweb);

            // If passweb looks like a PHP password_hash (bcrypt/argon2), use password_verify
            if (preg_match('/^\$(2[aby]|argon2i|argon2id)\$/i', $ph)) {
                if (password_verify($pw, $ph)) $ok = true;
            }

            // Try common hash algorithms (no length checks)
            if (!$ok) {
                if (hash('md5', $pw) === $ph) $ok = true;
                if (!$ok && hash('sha1', $pw) === $ph) $ok = true;
                if (!$ok && hash('sha256', $pw) === $ph) $ok = true;
                if (!$ok && hash('sha384', $pw) === $ph) $ok = true;
                if (!$ok && hash('sha512', $pw) === $ph) $ok = true;
            }

            // Plaintext comparison (some systems store raw password)
            if (!$ok && $pw === $ph) $ok = true;

            // Case-insensitive hex comparisons (handles upper/lower hex variants)
            if (!$ok) {
                if (strcasecmp(md5($pw), $ph) === 0) $ok = true;
                if (!$ok && strcasecmp(hash('sha256', $pw), $ph) === 0) $ok = true;
            }
        }

        if (!$ok) {
            if (isset($recordLoginFailure)) { $recordLoginFailure(); }
            if (isset($_SESSION['LOGIN_FAIL'][$clientIp])) { $_SESSION['LOGIN_FAIL'][$clientIp] = ['count'=>0,'first_ts'=>time()]; }
        if (function_exists('ob_get_length') && ob_get_length()) { @ob_clean(); }

        echo json_encode(['success' => false, 'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง']);
            exit;
        }

        // create or find local user record (users table expected) so auth_tokens.user_id can be numeric
        $userId = 0;
        $dbRole = null; // <-- NEW: detect DB role if present
        $ucheck = $conn->prepare("SELECT * FROM users WHERE BINARY email = ? LIMIT 1");
        if ($ucheck) {
            $ucheck->bind_param('s', $loginname);
            $ucheck->execute();
            $ures = $ucheck->get_result();
            if ($ures && $ures->num_rows > 0) {
                $urow = $ures->fetch_assoc();
                if (isset($urow['user_id'])) $userId = intval($urow['user_id']);
                elseif (isset($urow['id'])) $userId = intval($urow['id']);
                $fullname = $urow['fullname'] ?? $fullname;
                // NEW: use role from DB if present and valid
                if (!empty($urow['role']) && in_array($urow['role'], ['staff','admin','super_admin'])) {
                    $dbRole = $urow['role'];
                }
            } else {
                                // try to insert a minimal local user row (compatible with users table WITHOUT password)
                // NOTE: users.phone is NOT NULL in your latest schema, so we use a safe placeholder when creating rows.
                $dummyPhone = '0000000000';

                // detect existing columns to avoid SQL errors if schema differs
                $cols = [];
                $colRes = @$conn->query("SHOW COLUMNS FROM users");
                if ($colRes) {
                    while ($c = $colRes->fetch_assoc()) {
                        $cols[strtolower($c['Field'])] = true;
                    }
                    $colRes->free();
                }

                $fields = [];
                $placeholders = [];
                $types = '';
                $b0 = $b1 = $b2 = null;

                if (isset($cols['email'])) {
                    $fields[] = "email";
                    $placeholders[] = "?";
                    $types .= "s";
                    $b0 = $loginname;
                }
                if (isset($cols['fullname'])) {
                    $fields[] = "fullname";
                    $placeholders[] = "?";
                    $types .= "s";
                    $b1 = $fullname;
                }
                if (isset($cols['phone'])) {
                    $fields[] = "phone";
                    $placeholders[] = "?";
                    $types .= "s";
                    $b2 = $dummyPhone;
                }
                if (isset($cols['role'])) {
                    $fields[] = "role";
                    $placeholders[] = "'staff'";
                }

                if (!empty($fields)) {
                    $sqlIns = "INSERT INTO users (" . implode(", ", $fields) . ") VALUES (" . implode(", ", $placeholders) . ")";
                    $ins = @$conn->prepare($sqlIns);
                    if ($ins) {
                        // bind based on number of ? placeholders
                        if ($types === "s") {
                            $ins->bind_param("s", $b0);
                        } elseif ($types === "ss") {
                            $ins->bind_param("ss", $b0, $b1);
                        } elseif ($types === "sss") {
                            $ins->bind_param("sss", $b0, $b1, $b2);
                        }
                        if (@$ins->execute()) {
                            $userId = intval($ins->insert_id);
                        }
                        $ins->close();
                    }
                }
}

            // If we found a user record but it lacks a role, ensure it's set to 'staff'
            if (isset($urow) && empty($urow['role']) && !empty($loginname)) {
                $upd = $conn->prepare("UPDATE users SET role = 'staff' WHERE BINARY email = ? LIMIT 1");
                if ($upd) {
                    $upd->bind_param('s', $loginname);
                    $upd->execute();
                    $upd->close();
                    $dbRole = 'staff';
                } else {
                    // fallback to direct query if prepare failed
                    $conn->query("UPDATE users SET role = 'staff' WHERE BINARY email = '" . $conn->real_escape_string($loginname) . "' LIMIT 1");
                    $dbRole = 'staff';
                }
            }
            $ucheck->close();
        }

        // If we authenticated via HOSxP and we have a fullname from HOSxP,
        // prefer persisting that into the local users.fullname column so UI shows the HOSxP name.
        if (!empty($fullname)) {
            // Only attempt update if users table exists (defensive)
            $tblCheck = $conn->query("SHOW TABLES LIKE 'users'");
            if ($tblCheck && $tblCheck->num_rows > 0) {
                $upd = $conn->prepare("UPDATE users SET fullname = ? WHERE BINARY email = ? LIMIT 1");
                if ($upd) {
                    $upd->bind_param('ss', $fullname, $loginname);
                    $upd->execute();
                    $upd->close();
                }
            }
        }

        // If DB specified a role for this user, prefer it over default/roles_map
        if ($dbRole) {
            $role = $dbRole;
        } else {
            // Ensure local role-mapping table exists (use roles_map to avoid colliding with existing `roles` table)
            $conn->query("CREATE TABLE IF NOT EXISTS roles_map (
                id INT AUTO_INCREMENT PRIMARY KEY,
                loginname VARCHAR(128) NOT NULL UNIQUE,
                role ENUM('staff','admin','super_admin') NOT NULL DEFAULT 'staff'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $role = 'staff';
            $rstmt = $conn->prepare("SELECT role FROM roles_map WHERE BINARY loginname = ? LIMIT 1");
            if ($rstmt) {
                $rstmt->bind_param('s', $loginname);
                $rstmt->execute();
                $rres = $rstmt->get_result();
                if ($rres && $rres->num_rows > 0) {
                    $rr = $rres->fetch_assoc();
                    if (!empty($rr['role'])) $role = $rr['role'];
                }
                $rstmt->close();
            }

        }
        // Unified login: allow all roles from the same form (do not block by posted role)

        // fallback if users table missing or insert failed: create a synthetic numeric id using 0
        if ($userId === 0) {
            // leave as 0; existing auth_tokens rows use 0 for some staff entries
            $userId = 0;
        }

        // create per-tab auth token
        try {
            $token = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            $token = '';
        }

        if ($token) {
            $created = time();
            $showWelcomeFlag = ($role === 'admin' || $role === 'super_admin') ? 1 : 0;

            // Ensure auth_tokens.role enum includes 'super_admin' so we can store real role
            $colRes = $conn->query("SHOW COLUMNS FROM auth_tokens LIKE 'role'");
            if ($colRes && $colRes->num_rows) {
                $crow = $colRes->fetch_assoc();
                $colType = isset($crow['Type']) ? $crow['Type'] : ($crow['COLUMN_TYPE'] ?? '');
                if (stripos($colType, 'super_admin') === false) {
                    @ $conn->query("ALTER TABLE auth_tokens MODIFY COLUMN role ENUM('staff','admin','super_admin') NOT NULL DEFAULT 'staff'");
                }
            }

            // store actual role (allow 'super_admin')
            $tokenRoleForDb = $role;

            $ins = $conn->prepare("INSERT INTO auth_tokens (token, user_id, role, show_welcome, created_at, revoked) VALUES (?, ?, ?, ?, ?, 0)");
            if ($ins) {
                // bind tokenRoleForDb (string) rather than $role directly
                $ins->bind_param('sisii', $token, $userId, $tokenRoleForDb, $showWelcomeFlag, $created);
                if (!$ins->execute()) {
                    $conn->query("INSERT INTO auth_tokens (token,user_id,role,show_welcome,created_at,revoked) VALUES ('".$conn->real_escape_string($token)."', ".intval($userId).", '".$conn->real_escape_string($tokenRoleForDb)."', ".intval($showWelcomeFlag).", ".intval($created).", 0)");
                }
                $ins->close();
            } else {
                $conn->query("INSERT INTO auth_tokens (token,user_id,role,show_welcome,created_at,revoked) VALUES ('".$conn->real_escape_string($token)."', ".intval($userId).", '".$conn->real_escape_string($tokenRoleForDb)."', ".intval($showWelcomeFlag).", ".intval($created).", 0)");
            }

            if (!isset($_SESSION['auth_tokens']) || !is_array($_SESSION['auth_tokens'])) $_SESSION['auth_tokens'] = [];
            $_SESSION['auth_tokens'][$token] = ['created' => $created, 'show_welcome' => $showWelcomeFlag];
        } else {
            $token = '';
        }

        // regenerate session id to prevent session fixation and collisions
        if (function_exists('session_regenerate_id')) session_regenerate_id(true);
        // set session
        $_SESSION['user_id'] = $userId;
        $_SESSION['fullname'] = $fullname;
        $_SESSION['email'] = $loginname;
        $resolvedRole = resolveRoleFromDb($conn, $loginname);
        if ($resolvedRole) { $role = $resolvedRole; }

        $_SESSION['role'] = $role;
        if ($showWelcomeFlag) $_SESSION['show_welcome'] = true;

        // redirect mapping (based on resolved users.role)
if ($role === 'staff') {
    $redirect = 'nurse_reserve.html';
} elseif ($role === 'super_admin') {
    $redirect = 'permissions.html';
} else {
    $redirect = 'bed_reservation.html';
}

if (isset($_SESSION['LOGIN_FAIL'][$clientIp])) { $_SESSION['LOGIN_FAIL'][$clientIp] = ['count'=>0,'first_ts'=>time()]; }
        if (function_exists('ob_get_length') && ob_get_length()) { @ob_clean(); }

        echo json_encode([
            'success' => true,
            'message' => 'เข้าสู่ระบบสำเร็จ',
            'redirect' => $redirect,
            'fullname' => $fullname,
            'email' => $loginname,
            'user_id' => $userId,
            'role' => $role,
            'auth_token' => $token,
            'token' => $token // <-- ADD compatibility alias
        ]);
        $conn->close();
        exit;
    }

    
// 2) No local password tables in this system (role is managed in users/roles_map; auth uses HOSxP)
if (function_exists('ob_get_length') && ob_get_length()) { @ob_clean(); }
echo json_encode([
    'success' => false,
    'message' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'
]);
$conn->close();
exit;

// prepare and execute (case-sensitive email match)
    $sql = "SELECT * FROM `'users'` WHERE BINARY email = BINARY ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        if (function_exists('ob_get_length') && ob_get_length()) { @ob_clean(); }

        echo json_encode(['success' => false, 'message' => 'ข้อผิดพลาดในการเตรียมคำสั่งฐานข้อมูล: ' . $conn->error]);
        exit;
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        if (isset($recordLoginFailure)) { $recordLoginFailure(); }
        if (function_exists('ob_get_length') && ob_get_length()) { @ob_clean(); }

        echo json_encode(['success' => false, 'message' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง']);
        exit;
    }

    $user = $result->fetch_assoc();

    // NEW: Prefer role stored in DB record if present and valid
    if (!empty($user['role']) && in_array($user['role'], ['staff','admin','super_admin'])) {
        $role = $user['role'];
    } else {
        // keep posted/fallback $role (already set earlier)
    }

    // detect id field and fullname fallback
    $idField = null;
    if (isset($user['user_id']))
        $idField = 'user_id';
    elseif (isset($user['id']))
        $idField = 'id';
    elseif (isset($user['nurse_id']))
        $idField = 'nurse_id';

    $userId = $idField ? intval($user[$idField]) : 0;
    $fullname = $user['fullname'] ?? $user['name'] ?? '';

    if (!isset($user['password'])) {
        if (function_exists('ob_get_length') && ob_get_length()) { @ob_clean(); }

        echo json_encode(['success' => false, 'message' => 'บัญชีผู้ใช้นี้ยังไม่มีรหัสผ่านในระบบ']);
        exit;
    }

        if (password_verify($password, $user['password'])) {
        // Unified login: allow all roles from the same form (do not block by posted role)

        // regenerate session id to prevent session fixation and collisions
        if (function_exists('session_regenerate_id')) session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['fullname'] = $fullname;
        $_SESSION['email'] = $email;
        $_SESSION['role'] = $role;

        // create per-tab auth token, store in DB and session fallback
        try {
            $token = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            $token = '';
        }

        if ($token) {
            // insert into DB (table: auth_tokens) — ensure table exists per schema above
            $created = time();
            $showWelcomeFlag = ($role === 'admin') ? 1 : 0;

            // Ensure auth_tokens.role enum includes 'super_admin' and store actual role
            $colRes = $conn->query("SHOW COLUMNS FROM auth_tokens LIKE 'role'");
            if ($colRes && $colRes->num_rows) {
                $crow = $colRes->fetch_assoc();
                $colType = isset($crow['Type']) ? $crow['Type'] : ($crow['COLUMN_TYPE'] ?? '');
                if (stripos($colType, 'super_admin') === false) {
                    @ $conn->query("ALTER TABLE auth_tokens MODIFY COLUMN role ENUM('staff','admin','super_admin') NOT NULL DEFAULT 'staff'");
                }
            }

            $tokenRoleForDb = $role;

            $ins = $conn->prepare("INSERT INTO auth_tokens (token, user_id, role, show_welcome, created_at, revoked) VALUES (?, ?, ?, ?, ?, 0)");
            if ($ins) {
                $ins->bind_param('sisii', $token, $userId, $tokenRoleForDb, $showWelcomeFlag, $created);
                if (!$ins->execute()) {
                    $conn->query("INSERT INTO auth_tokens (token,user_id,role,show_welcome,created_at,revoked) VALUES ('".$conn->real_escape_string($token)."', ".intval($userId).", '".$conn->real_escape_string($tokenRoleForDb)."', ".intval($showWelcomeFlag).", ".intval($created).", 0)");
                }
                $ins->close();
            } else {
                // fallback direct insert
                $conn->query("INSERT INTO auth_tokens (token,user_id,role,show_welcome,created_at,revoked) VALUES ('".$conn->real_escape_string($token)."', ".intval($userId).", '".$conn->real_escape_string($tokenRoleForDb)."', ".intval($showWelcomeFlag).", ".intval($created).", 0)");
            }

            // also keep token in session array as compatibility/fallback
            if (!isset($_SESSION['auth_tokens']) || !is_array($_SESSION['auth_tokens'])) $_SESSION['auth_tokens'] = [];
            $_SESSION['auth_tokens'][$token] = ['created' => $created, 'show_welcome' => $showWelcomeFlag];
        } else {
            $token = '';
        }

        // --- CHANGED: staff now redirected to bed_reservation.html (nurse_dashboard removed) ---
        if ($role === 'staff') {
            // staff should go to nurse_reserve page
            // Resolve role from users.role (preferred)
$resolvedRole = resolveRoleFromDb($conn, $loginname);
if ($resolvedRole) { $role = $resolvedRole; }

$redirect = 'nurse_reserve.html';
        } elseif ($role === 'super_admin') {
            // super_admins only use permissions.html
            $redirect = 'permissions.html';
        } else {
            // admin: go to register_step2 and request a one-time welcome popup
            $_SESSION['show_welcome'] = true;
            $redirect = 'bed_reservation.html';
        }

        if (function_exists('ob_get_length') && ob_get_length()) { @ob_clean(); }


        echo json_encode([
            'success' => true,
            'message' => 'เข้าสู่ระบบสำเร็จ',
            'redirect' => $redirect,
            'fullname' => $fullname,
            'email' => $email,
            'user_id' => $userId,
            'role' => $role,
            'auth_token' => $token,
            'token' => $token // <-- ADD compatibility alias
        ]);
    } else {
        if (isset($recordLoginFailure)) { $recordLoginFailure(); }
        if (function_exists('ob_get_length') && ob_get_length()) { @ob_clean(); }

        echo json_encode(['success' => false, 'message' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง']);
    }

    $stmt->close();
}
$conn->close();
?>