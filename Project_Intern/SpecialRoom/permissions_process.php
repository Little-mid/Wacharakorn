<?php
// Ensure any stray output, notices or warnings are captured and returned as JSON
// กำหนด session timeout 5 นาที
session_set_cookie_params(["lifetime" => 300]);
session_start();
ob_start();
ini_set('display_errors', '0');

set_error_handler(function($errno, $errstr, $errfile, $errline){
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

set_exception_handler(function($e){
    http_response_code(500); 
    $debug = trim(ob_get_clean() ?: '');
    header('Content-Type: application/json; charset=utf-8');
    $msg = method_exists($e, 'getMessage') ? $e->getMessage() : (string)$e;
    echo json_encode([ 'success' => false, 'message' => $msg, 'debug' => $debug ]);
    exit;
});

// Lightweight endpoint to manage roles_map table
// Expects include of config.php which should provide $conn (mysqli)

function res($ok,$data=[],$msg=''){
    $buf = trim(ob_get_clean() ?: '');
    $payload = array_merge(['success'=>$ok,'message'=>$msg], is_array($data)?['data'=>$data]:[]);
    if($buf !== '') $payload['debug'] = $buf;
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

// helper: check table existence (defined at file scope so it's always available)
function table_exists_local($conn, $tbl){
    $t = $conn->real_escape_string($tbl);
    $res = $conn->query("SHOW TABLES LIKE '" . $t . "'");
    return ($res && $res->num_rows > 0);
}

// Helper: Get HOSxP user by loginname (returns array with loginname, name, account_disable)
function get_hosxp_user($loginname) {
    global $hosxpConn;
    // Log loginname for debug
    error_log('get_hosxp_user called with loginname: [' . $loginname . ']');
    if (file_exists(__DIR__ . '/db_hosxp.php')) require_once __DIR__ . '/db_hosxp.php';
    if (!isset($hosxpConn) || !($hosxpConn instanceof mysqli)) {
        error_log('hosxpConn not set or invalid');
        return null;
    }
    $hsql = "SELECT loginname, name, COALESCE(TRIM(account_disable), '') AS account_disable FROM opduser WHERE LOWER(TRIM(loginname)) = LOWER(TRIM(?)) LIMIT 1";
    $hstmt = $hosxpConn->prepare($hsql);
    if (!$hstmt) {
        error_log('hosxpConn prepare failed');
        return null;
    }
    $hstmt->bind_param('s', $loginname);
    $hstmt->execute();
    $hres = $hstmt->get_result();
    if ($hres && $hres->num_rows > 0) {
        $row = $hres->fetch_assoc();
        $hstmt->close();
        error_log('get_hosxp_user found: ' . json_encode($row));
        return $row;
    }
    $hstmt->close();
    error_log('get_hosxp_user not found for: [' . $loginname . ']');
    return null;
}

$action = $_GET['action'] ?? '';

// try to load external config if present (may define $conn)
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// If $conn isn't set by config.php, attempt a safe local fallback (edit credentials if needed)
if (!isset($conn) || !($conn instanceof mysqli)) {
    $DB_HOST = '127.0.0.1';
    $DB_USER = 'root';
    $DB_PASS = '';
    $DB_NAME = 'Room_management'; // default DB from provided SQL dump
    $conn = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    if ($conn->connect_errno) {
        error_log("DB connect error ({$DB_HOST}/{$DB_NAME}): " . $conn->connect_error);
        unset($conn); // keep behavior consistent below
    } else {
        $conn->set_charset('utf8mb4');
    }
}

if(!isset($conn) || !($conn instanceof mysqli)){
    res(false, [], 'ไม่พบการเชื่อมต่อฐานข้อมูล (config.php ไม่ได้กำหนด $conn และ fallback ล้มเหลว)');
}

// HOSxP search endpoint: query roles_map first, then opduser table if available
if($action === 'hosxp_search'){
    $q = trim($_GET['q'] ?? '');
    if($q === '') res(false, [], 'คำค้นว่าง');

    $like = '%' . str_replace(['%','_'], ['\%','\_'], mb_strtolower($q, 'UTF-8')) . '%';

    // 1) Try local roles_map first (if exists) to provide immediate matches
    if (table_exists_local($conn, 'roles_map')) {
        $sql = "SELECT TRIM(`loginname`) AS loginname, `name`, `role`
                FROM `roles_map`
                WHERE LOWER(TRIM(CONVERT(`loginname` USING utf8mb4))) LIKE ?
                   OR LOWER(TRIM(CONVERT(`name` USING utf8mb4))) LIKE ?
                LIMIT 200";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param('ss', $like, $like);
            $stmt->execute();
            $res = $stmt->get_result();
            $out = [];
            while($r = $res->fetch_assoc()){
                $out[] = ['loginname' => $r['loginname'], 'name' => $r['name'] ?? '', 'role' => $r['role'] ?? ''];
            }
            $stmt->close();
            if (count($out) > 0) {
                res(true, $out);
            }
            // fallthrough to HOSxP search if no local matches
        }
    }

    // 2) Try to include HOSxP connection and search there
    if (file_exists(__DIR__ . '/db_hosxp.php')) require_once __DIR__ . '/db_hosxp.php';
    if (!isset($hosxpConn) || !($hosxpConn instanceof mysqli)) {
        // If roles_map had no matches and HOSxP cannot be contacted -> report not found
        res(false, [], 'ไม่พบผู้ใช้งาน');
    }

    // search by loginname or name (case-insensitive) in HOSxP
    $sql = "SELECT loginname FROM opduser WHERE LOWER(TRIM(CONVERT(`loginname` USING utf8mb4))) LIKE ? OR LOWER(TRIM(CONVERT(`name` USING utf8mb4))) LIKE ? LIMIT 200";
    if ($stmt = $hosxpConn->prepare($sql)){
        $stmt->bind_param('ss', $like, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while($r = $res->fetch_assoc()){
            $out[] = ['loginname' => $r['loginname'], 'name' => $r['name']];
        }
        $stmt->close();
        if (count($out) > 0) {
            res(true, $out);
        } else {
            // explicit not found response when neither roles_map nor HOSxP have matches
            res(false, [], 'ไม่พบผู้ใช้งาน');
        }
    }

    res(false, [], 'ไม่สามารถค้นหา HOSxP ได้');
}

// Check HOSxP user for validation (check if exists and account_disable status)
if($action === 'check_hosxp_user'){
    $loginname = isset($_GET['loginname']) ? trim((string)$_GET['loginname']) : '';
    if($loginname === '') res(false, [], 'ไม่ระบุ loginname');

    $row = get_hosxp_user($loginname);
    if ($row !== null) {
        $accountDisable = isset($row['account_disable']) ? trim((string)$row['account_disable']) : '';
        // Treat empty account_disable as 'N' (active)
        $accountDisableNorm = ($accountDisable === '' || is_null($row['account_disable'])) ? 'N' : strtoupper($accountDisable);
        if ($accountDisableNorm !== 'N') {
            res(false, [], 'บัญชีนี้ถูกปิดการใช้งานใน HOSxP (account_disable=' . $accountDisable . ')');
        }
        res(true, [
            'account_disable' => $accountDisable,
            'fullname' => $row['name'] ?? ''
        ]);
    }
    res(false, [], 'ไม่พบบัญชีนี้ใน HOSxP');
}

// Stats for dashboard
if($action === 'stats'){
    $counts = ['total'=>0,'admin'=>0,'staff'=>0,'super_admin'=>0];
    // prefer roles_map table if present
    if(table_exists_local($conn, 'roles_map')){
        $q = "SELECT role, COUNT(*) AS c FROM roles_map GROUP BY role";
        $tbl = 'roles_map';
    } else {
        $q = "SELECT role, COUNT(*) AS c FROM users GROUP BY role";
        $tbl = 'users';
    }
    if($result = $conn->query($q)){
        while($r = $result->fetch_assoc()){
            $role = strtolower(trim($r['role'] ?? ''));
            $c = intval($r['c']);
            $counts['total'] += $c;
            if(array_key_exists($role, $counts)) $counts[$role] = $c;
        }
    }

    // try to get last updated time: first try information_schema on chosen table, then fallback
    $updated_at = null;
    $schemaQ = "SELECT UPDATE_TIME FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . $conn->real_escape_string($tbl) . "' LIMIT 1";
    if($res2 = $conn->query($schemaQ)){
        $r2 = $res2->fetch_assoc();
        if(!empty($r2['UPDATE_TIME'])) $updated_at = $r2['UPDATE_TIME'];
    }

    if(!$updated_at) $updated_at = date('Y-m-d H:i:s');

    $data = array_merge($counts, ['updated_at' => $updated_at]);
    res(true, $data);
}

if($action === 'list'){
    // If roles_map exists, prefer it (may contain name column from HOSxP)
    if(table_exists_local($conn, 'roles_map')){
        // show loginname, role, and optional name
        $cols = "TRIM(`loginname`) AS loginname, `role`";
        // include name column if present
        $r = $conn->query("SHOW COLUMNS FROM `roles_map` LIKE 'name'");
        if($r && $r->num_rows > 0) $cols .= ", `name`";
        $q = "SELECT $cols FROM `roles_map` ORDER BY TRIM(`loginname`) ASC";
        if($stmt = $conn->prepare($q)){
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = [];
            while($r = $res->fetch_assoc()) $rows[] = $r;
            res(true, $rows);
        }
        res(false, [], 'ไม่สามารถอ่าน roles_map ได้');
    }

    // fallback: allow different user identifier column names (email/loginname/username)
    $idCol = 'email';
    $cands = ['email','loginname','username','user_email'];
    foreach($cands as $c){
        $esc = $conn->real_escape_string($c);
        $r = $conn->query("SHOW COLUMNS FROM `users` LIKE '$esc'");
        if($r && $r->num_rows > 0){ $idCol = $c; break; }
    }

    $safeCol = $conn->real_escape_string($idCol);
    // return trimmed loginname for cleaner display
    $q = "SELECT TRIM(`$safeCol`) AS loginname, role FROM `users` ORDER BY TRIM(`$safeCol`) ASC";
    if($stmt = $conn->prepare($q)){
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while($r = $res->fetch_assoc()) $rows[] = $r;
        res(true, $rows);
    }
    res(false, [], 'ไม่สามารถอ่านข้อมูลได้');
}


// propagate role change to local tables (users.user_email, user_roles.loginname, nurses.email)
function sync_role_to_local_users($conn, $login, $role)
{
    if (!table_exists_local($conn, 'users')) return;

    $login = trim((string)$login);
    if ($login === '') return;

    // Determine which column identifies the login in users table (prefer email)
    $idCol = 'email';
    $colChk = $conn->query("SHOW COLUMNS FROM users LIKE 'email'");
    if (!$colChk || $colChk->num_rows === 0) {
        // fallback to loginname if some DB uses different name
        $colChk2 = $conn->query("SHOW COLUMNS FROM users LIKE 'loginname'");
        if ($colChk2 && $colChk2->num_rows > 0) $idCol = 'loginname';
        if ($colChk2) $colChk2->free();
    }
    if ($colChk) $colChk->free();

    // Check required columns exist
    $hasFullname = false;
    $hasPhone = false;
    $c1 = $conn->query("SHOW COLUMNS FROM users LIKE 'fullname'");
    if ($c1 && $c1->num_rows > 0) $hasFullname = true;
    if ($c1) $c1->free();
    $c2 = $conn->query("SHOW COLUMNS FROM users LIKE 'phone'");
    if ($c2 && $c2->num_rows > 0) $hasPhone = true;
    if ($c2) $c2->free();

    // Normalize (avoid collation mix issues)
    $norm = mb_strtolower($login, 'UTF-8');

    // Check if user exists
    $stmtFind = $conn->prepare("SELECT user_id, fullname FROM users WHERE LOWER(TRIM(CONVERT($idCol USING utf8mb4))) = ? LIMIT 1");
    if (!$stmtFind) return;
    $stmtFind->bind_param('s', $norm);
    $stmtFind->execute();
    $resFind = $stmtFind->get_result();

    $userId = null;
    $existingFullname = null;
    if ($resFind && $resFind->num_rows > 0) {
        $row = $resFind->fetch_assoc();
        $userId = intval($row['user_id']);
        $existingFullname = $row['fullname'] ?? null;
    }
    $stmtFind->close();

    // If missing, create a new users row (needed for cancellation history lookups)
    if (!$userId) {
        $name = null;
        if (table_exists_local($conn, 'roles_map')) {
            $stmtName = $conn->prepare("SELECT name FROM roles_map WHERE LOWER(TRIM(CONVERT(loginname USING utf8mb4))) = ? LIMIT 1");
            if ($stmtName) {
                $stmtName->bind_param('s', $norm);
                $stmtName->execute();
                $resName = $stmtName->get_result();
                if ($resName && $resName->num_rows > 0) {
                    $r = $resName->fetch_assoc();
                    $name = $r['name'] ?? null;
                }
                $stmtName->close();
            }
        }
        $name = trim((string)$name);
        if ($name === '') $name = $login;

        if ($hasFullname && $hasPhone && $idCol === 'email') {
            $phone = '0000000000';
            $ins = $conn->prepare("INSERT INTO users (fullname, email, phone, role) VALUES (?, ?, ?, ?)");
            if ($ins) {
                $ins->bind_param('ssss', $name, $login, $phone, $role);
                @ $ins->execute();
                $ins->close();
            }
        } else {
            // Legacy fallback: insert minimal (may fail if columns are NOT NULL)
            $ins = $conn->prepare("INSERT INTO users ($idCol, role) VALUES (?, ?)");
            if ($ins) {
                $ins->bind_param('ss', $login, $role);
                @ $ins->execute();
                $ins->close();
            }
        }
    }

    // Always update role in users
    $stmtU = $conn->prepare("UPDATE users SET role = ? WHERE LOWER(TRIM(CONVERT($idCol USING utf8mb4))) = ?");
    if ($stmtU) {
        $stmtU->bind_param('ss', $role, $norm);
        @$stmtU->execute();
        $stmtU->close();
    }

    // Also keep legacy user_roles table in sync (if present)
    if (table_exists_local($conn, 'user_roles')) {
        $stmtUR = $conn->prepare("UPDATE user_roles SET role = ? WHERE LOWER(TRIM(CONVERT($idCol USING utf8mb4))) = ?");
        if ($stmtUR) {
            $stmtUR->bind_param('ss', $role, $norm);
            @$stmtUR->execute();
            $stmtUR->close();
        }
    }
}


if($action === 'save'){
    $login = trim($_POST['loginname'] ?? $_POST['email'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $role_id = trim($_POST['role_id'] ?? '');
    // Debug log: log input loginname and role
    error_log('[SAVE] loginname=' . $login . ', role=' . $role . ', role_id=' . $role_id);

    // If role not provided but role_id is, try to map via roles table
    if($role === '' && $role_id !== ''){
        if($conn->query("SHOW TABLES LIKE 'roles'")->num_rows > 0){
            // determine which primary id column exists on roles table: prefer `id`, fallback to `role_id`
            $rolesIdCol = null;
            $c1 = $conn->query("SHOW COLUMNS FROM `roles` LIKE 'id'");
            if ($c1 && $c1->num_rows > 0) $rolesIdCol = 'id';
            else {
                $c2 = $conn->query("SHOW COLUMNS FROM `roles` LIKE 'role_id'");
                if ($c2 && $c2->num_rows > 0) $rolesIdCol = 'role_id';
            }

            // If role_id looks numeric and we have a numeric id column, query by that
            if (is_numeric($role_id) && $rolesIdCol !== null) {
                $rid = intval($role_id);
                $sql = "SELECT * FROM `roles` WHERE `" . $conn->real_escape_string($rolesIdCol) . "` = ? LIMIT 1";
                $rstmt = $conn->prepare($sql);
                if ($rstmt) {
                    // bind as integer
                    $rstmt->bind_param('i', $rid);
                    $rstmt->execute();
                    $rres = $rstmt->get_result()->fetch_assoc();
                    if ($rres) {
                        foreach (['name', 'role', 'slug', 'label'] as $colName) {
                            if (isset($rres[$colName]) && $rres[$colName] !== null) { $role = (string)$rres[$colName]; break; }
                        }
                        if ($role === '') {
                            foreach ($rres as $k => $v) { if (!in_array($k, [$rolesIdCol, 'role_id'])) { $role = (string)$v; break; } }
                        }
                    }
                    $rstmt->close();
                }
            } else {
                // non-numeric role_id: try to match by slug or name columns (common cases)
                $tryCols = ['slug','name','role'];
                foreach ($tryCols as $col) {
                    $colEsc = $conn->real_escape_string($col);
                    $r = $conn->query("SHOW COLUMNS FROM `roles` LIKE '$colEsc'");
                    if ($r && $r->num_rows > 0) {
                        $s = $conn->prepare("SELECT * FROM `roles` WHERE LOWER(TRIM(CONVERT(`$colEsc` USING utf8mb4))) = LOWER(TRIM(?)) LIMIT 1");
                        if ($s) {
                            $s->bind_param('s', $role_id);
                            $s->execute();
                            $rr = $s->get_result()->fetch_assoc();
                            $s->close();
                            if ($rr) {
                                foreach (['name', 'role', 'slug', 'label'] as $colName) {
                                    if (isset($rr[$colName]) && $rr[$colName] !== null) { $role = (string)$rr[$colName]; break; }
                                }
                                if ($role === '') {
                                    foreach ($rr as $k => $v) { if (!in_array($k, ['id','role_id'])) { $role = (string)$v; break; } }
                                }
                                break;
                            }
                        }
                    }
                }
            }
        }
    }

    if($login === '' || $role === '') res(false, [], 'ข้อมูลไม่ครบ (loginname, role_id or role)');

    // ตรวจสอบ HOSxP user และ account_disable
    $hosxpRow = get_hosxp_user($login);
    error_log('[SAVE] get_hosxp_user result: ' . json_encode($hosxpRow));
    if ($hosxpRow === null) {
        error_log('[SAVE] ไม่พบบัญชีนี้ใน HOSxP');
        res(false, [], 'ไม่พบบัญชีนี้ใน HOSxP');
    }
    $accDisable = isset($hosxpRow['account_disable']) ? trim((string)$hosxpRow['account_disable']) : '';
    error_log('[SAVE] account_disable value: ' . $accDisable);
    // Treat empty account_disable as 'N' (active)
    $accDisableNorm = ($accDisable === '' || is_null($hosxpRow['account_disable'])) ? 'N' : strtoupper($accDisable);
    if ($accDisableNorm !== 'N') {
        error_log('[SAVE] บัญชีนี้ถูกปิดการใช้งานใน HOSxP');
        res(false, [], 'บัญชีนี้ถูกปิดการใช้งานใน HOSxP (account_disable=' . $accDisable . ')');
    }

    // determine identifier column
    $idCol = 'email';
    $cands = ['email','loginname','username','user_email'];
    foreach($cands as $c){
        $r = $conn->query("SHOW COLUMNS FROM `users` LIKE '" . $conn->real_escape_string($c) . "'");
        if($r && $r->num_rows > 0){ $idCol = $c; break; }
    }

    // รองรับแก้ไข role (edit) เมื่อมี flag edit=1
    $isEdit = isset($_POST['edit']) && $_POST['edit'] == '1';
    if(table_exists_local($conn, 'roles_map')){
        $inputRaw = trim($_POST['loginname'] ?? $_POST['email'] ?? $_POST['name'] ?? '');
        $searchInput = mb_strtolower($inputRaw, 'UTF-8');
        if($isEdit){
            // update role
            $upd = $conn->prepare("UPDATE roles_map SET role = ? WHERE LOWER(TRIM(CONVERT(loginname USING utf8mb4))) = ?");
            if($upd){
                $upd->bind_param('ss', $role, $searchInput);
                if($upd->execute()){
                    sync_role_to_local_users($conn, $inputRaw, $role);
                    res(true, [], 'updated');
                }
                $upd->close();
            }
            res(false, [], 'ไม่สามารถแก้ไข roles_map ได้');
        }
        // ...existing code for insert (เพิ่มผู้ใช้ใหม่)...
        $nameRaw  = trim($_POST['name'] ?? '');
        $searchName  = mb_strtolower($nameRaw, 'UTF-8');
        $hasName = false;
        $colRes = $conn->query("SHOW COLUMNS FROM `roles_map` LIKE 'name'");
        if($colRes && $colRes->num_rows > 0) $hasName = true;
        if($hasName){
            $sel = $conn->prepare(
                "SELECT id FROM `roles_map`
                 WHERE LOWER(TRIM(CONVERT(`loginname` USING utf8mb4))) = ?
                    OR LOWER(TRIM(CONVERT(`name` USING utf8mb4))) = ?
                 LIMIT 1"
            );
            $tokenForName = $searchName !== '' ? $searchName : $searchInput;
            $sel->bind_param('ss', $searchInput, $tokenForName);
        } else {
            $sel = $conn->prepare(
                "SELECT id FROM `roles_map`
                 WHERE LOWER(TRIM(CONVERT(`loginname` USING utf8mb4))) = ?
                 LIMIT 1"
            );
            $sel->bind_param('s', $searchInput);
        }
        if(!$sel->execute()) res(false, [], 'DB error (roles_map select)');
        $found = $sel->get_result()->fetch_assoc();
        $sel->close();
        error_log('[SAVE] roles_map duplicate check: found=' . json_encode($found) . ' input=' . $searchInput . ' name=' . $searchName);
        if($found){
            res(false, [], 'มีผู้ใช้นี้อยู่แล้วในระบบ');
        }
        $ins_login = trim($hosxpRow['loginname']);
        $ins_name  = trim($hosxpRow['name'] ?? '');
        if($hasName){
            $ins_name_clean = ($ins_name === '') ? null : $ins_name;
            if ($ins_name_clean === null){
                $ins = $conn->prepare("INSERT INTO `roles_map` (`loginname`,`role`,`name`) VALUES (?,?,NULL)");
                if($ins) $ins->bind_param('ss', $ins_login, $role);
            } else {
                $ins = $conn->prepare("INSERT INTO `roles_map` (`loginname`,`role`,`name`) VALUES (?,?,?)");
                if($ins) $ins->bind_param('sss', $ins_login, $role, $ins_name_clean);
            }
        } else {
            $ins = $conn->prepare("INSERT INTO `roles_map` (`loginname`,`role`) VALUES (?,?)");
            if($ins) $ins->bind_param('ss', $ins_login, $role);
        }
        if($ins && $ins->execute()){
            sync_role_to_local_users($conn, $ins_login, $role);
            res(true, [], 'inserted');
        }
        res(false, [], 'ไม่สามารถเพิ่ม roles_map ได้');
    }

    // fallback: users table, only allow insert if not exists
    $col = $conn->real_escape_string($idCol);
    $searchLogin = mb_strtolower($login, 'UTF-8');
    $selSql = "SELECT COUNT(*) AS c FROM `users` WHERE LOWER(TRIM(CONVERT(`$col` USING utf8mb4))) = ?";
    $sel = $conn->prepare($selSql);
    $sel->bind_param('s',$searchLogin);
    if(!$sel->execute()) res(false, [], 'DB error');
    $r = $sel->get_result()->fetch_assoc();
    if($r && intval($r['c'])>0){
        error_log('[SAVE] users duplicate check: found=' . json_encode($r) . ' input=' . $login);
        res(false, [], 'มีผู้ใช้นี้อยู่แล้วในระบบ');
    } else {
        $insSql = "INSERT INTO `users` (`$col`, `role`) VALUES (?, ?)";
        $ins = $conn->prepare($insSql);
        $ins->bind_param('ss',$login,$role);
        if($ins->execute()){
            sync_role_to_local_users($conn, $login, $role);
            res(true, [], 'inserted');
        }
        res(false, [], 'ไม่สามารถเพิ่มได้');
    }
}

if($action === 'delete'){
    $login = trim($_POST['loginname'] ?? $_POST['email'] ?? '');
    if($login === '') res(false, [], 'loginname/email ว่าง');

    $searchLogin = mb_strtolower($login, 'UTF-8');

    // if roles_map exists, delete from it first
    if(table_exists_local($conn, 'roles_map')){
        $del = $conn->prepare("DELETE FROM `roles_map` WHERE LOWER(TRIM(CONVERT(`loginname` USING utf8mb4))) = ?");
        $del->bind_param('s',$searchLogin);
        if($del->execute()){
            sync_role_to_local_users($conn, $login, 'staff');
            res(true, [], 'deleted');
        }
        // fallthrough to users delete attempt
    }

    $idCol = 'email';
    foreach(['email','loginname','username','user_email'] as $c){
        $r = $conn->query("SHOW COLUMNS FROM `users` LIKE '" . $conn->real_escape_string($c) . "'");
        if($r && $r->num_rows > 0){ $idCol = $c; break; }
    }

    $col = $conn->real_escape_string($idCol);
    $del = $conn->prepare("DELETE FROM `users` WHERE LOWER(TRIM(CONVERT(`$col` USING utf8mb4))) = ?");
    $del->bind_param('s',$searchLogin);
    if($del->execute()){
        // on delete, set associated local user roles back to default 'staff'
        sync_role_to_local_users($conn, $login, 'staff');
        res(true, [], 'deleted');
    }
    res(false, [], 'ลบไม่สำเร็จ');
}

res(false, [], 'action ไม่ถูกต้อง');

?>
