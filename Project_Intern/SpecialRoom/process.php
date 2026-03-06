<?php
header('Content-Type: application/json; charset=utf-8');
/* Early duplicate manage_request block removed - use main handler after DB connection */


// 30-minute inactivity timeout (in seconds)
$SESSION_TIMEOUT = 30 * 60;
ini_set('session.gc_maxlifetime', (string) $SESSION_TIMEOUT);
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
require_once 'db_hosxp.php';
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Using PHP session for authentication. No JWT dependency.
// Keep $tokenPayload = null for backward-compatible helper calls.
$tokenPayload = null;

// --- NEW: accept Bearer token and resolve real user role (prefer DB-stored role such as 'super_admin') ---
$authHeader = '';
if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = trim($_SERVER['HTTP_AUTHORIZATION']);
} elseif (function_exists('apache_request_headers')) {
    $hdrs = apache_request_headers();
    if (!empty($hdrs['Authorization']))
        $authHeader = trim($hdrs['Authorization']);
    elseif (!empty($hdrs['authorization']))
        $authHeader = trim($hdrs['authorization']);
}

$bearerToken = '';
if ($authHeader && preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
    $bearerToken = $m[1];
}

$finalRole = null;
$isAuthenticated = false;
$tokenUserId = 0;

// If bearer token provided, try to resolve user via auth_tokens -> prefer DB role (users/nurses)
if ($bearerToken) {
    $tq = $conn->prepare("SELECT user_id, role, revoked FROM auth_tokens WHERE token = ? LIMIT 1");
    if ($tq) {
        $tq->bind_param('s', $bearerToken);
        $tq->execute();
        $tres = $tq->get_result();
        if ($tres && $tres->num_rows === 1) {
            $trow = $tres->fetch_assoc();
            if (empty($trow['revoked'])) {
                $tokenUserId = intval($trow['user_id']);
                $tokenRole = $trow['role'] ?? null;
                // prefer explicit role from users table
                if ($tokenUserId > 0) {
                    $u = $conn->prepare("SELECT role FROM users WHERE user_id = ? LIMIT 1");
                    if ($u) {
                        $u->bind_param('i', $tokenUserId);
                        $u->execute();
                        $ur = $u->get_result();
                        if ($ur && $ur->num_rows === 1) {
                            $uro = $ur->fetch_assoc();
                            if (!empty($uro['role']))
                                $finalRole = $uro['role'];
                        }
                        $u->close();
                    }
                    // fallback to nurses table
                    if (!$finalRole) {
                        $n = $conn->prepare("SELECT role FROM nurses WHERE nurse_id = ? LIMIT 1");
                        if ($n) {
                            $n->bind_param('i', $tokenUserId);
                            $n->execute();
                            $nr = $n->get_result();
                            if ($nr && $nr->num_rows === 1) {
                                $nro = $nr->fetch_assoc();
                                if (!empty($nro['role']))
                                    $finalRole = $nro['role'];
                            }
                            $n->close();
                        }
                    }
                }
                // last resort: use role from token row
                if (!$finalRole)
                    $finalRole = $tokenRole;
                // mark authenticated for this request only. DO NOT write token identity into
                // the shared PHP session because that would overwrite other tabs' identities.
                if ($tokenUserId > 0) {
                    $isAuthenticated = true;
                    // set token payload for helpers if needed (request-scoped only)
                    $tokenPayload = ['user_id' => $tokenUserId, 'role' => $finalRole, 'token' => $bearerToken];
                }
            }
        }
        $tq->close();
    }
}

// If no bearer token auth, fallback to existing session auth
if (!$isAuthenticated) {
    $isAuthenticated = isset($_SESSION['user_id']) && intval($_SESSION['user_id']) > 0;
    $finalRole = $_SESSION['role'] ?? null;
}

// NEW: consider both admin and super_admin as admin for management APIs/pages
$isAdmin = ($isAuthenticated && in_array(strtolower((string) $finalRole), ['admin', 'super_admin'], true));
// allow staff flag
$isStaff = ($isAuthenticated && strtolower((string) $finalRole) === 'staff');

// remove automatic redirect for unauthenticated requests so AJAX gets JSON response
// (previously there was a block: if (!isset($_SESSION['user_id'])) { header("Location: login_process.php"); exit; } )

// --- NEW: helper ตรวจสอบว่าตารางมีอยู่หรือไม่ ---
function checkRequiredTables($conn, $tables = [])
{
    $missing = [];
    foreach ($tables as $t) {
        $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'");
        if (!$r || $r->num_rows === 0) {
            $missing[] = $t;
        }
        if ($r)
            $r->free();
    }
    return $missing;
}

// ✅ urgency ต้องเป็น 1 (Walk-in) หรือ 2 (Appointment) เท่านั้น
function normalizeUrgency($val, $appointmentAt = null)
{
    $n = ($val === null || $val === '') ? null : intval($val);
    if ($n === 1 || $n === 2)
        return $n;

    // ถ้าค่าเพี้ยน (เช่น 4) ให้เดาจาก appointment_at:
    // มีวันนัด => Appointment(2), ไม่มี => Walk-in(1)
    return !empty($appointmentAt) ? 2 : 1;
}



// ensure public_requests table creation / migration helper (single source of truth)
function ensurePublicRequestsTable($conn)
{
    // create table if not exists (full desired schema)
    $create = "
		CREATE TABLE IF NOT EXISTS public_requests (
			id INT AUTO_INCREMENT PRIMARY KEY,
			hn VARCHAR(64) NOT NULL,
			patient_name VARCHAR(255) DEFAULT NULL,
			phone VARCHAR(32) DEFAULT NULL,
			bed_number INT DEFAULT NULL,
			note VARCHAR(255) DEFAULT NULL,
			status ENUM('pending','confirmed','done','canceled','handled') NOT NULL DEFAULT 'pending',
			urgency TINYINT(1) NOT NULL DEFAULT 1,
			patient_age INT DEFAULT NULL,
			patient_gender VARCHAR(32) DEFAULT NULL,
			user_rh VARCHAR(255) DEFAULT NULL,
			created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT NULL
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
	";
    $conn->query($create);

    // determine current database name
    $dbName = null;
    $res = $conn->query("SELECT DATABASE() AS dbname");
    if ($res) {
        $row = $res->fetch_assoc();
        $dbName = $row['dbname'] ?? null;
        $res->free();
    }
    if (!$dbName)
        return;

    // inspect columns in information_schema for public_requests
    $dbEsc = $conn->real_escape_string($dbName);
    $cols = [];
    $statusType = null;
    $sql = "SELECT COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '$dbEsc' AND TABLE_NAME = 'public_requests'";
    $res2 = $conn->query($sql);
    if ($res2) {
        while ($r = $res2->fetch_assoc()) {
            $cols[$r['COLUMN_NAME']] = true;
            if ($r['COLUMN_NAME'] === 'status')
                $statusType = $r['COLUMN_TYPE'];
        }
        $res2->free();
    }

    // add patient_name if missing
    if (!isset($cols['patient_name'])) {
        // best-effort; ignore errors
        @$conn->query("ALTER TABLE public_requests ADD COLUMN patient_name VARCHAR(255) DEFAULT NULL AFTER hn");
    }
    // เพิ่ม note ถ้ายังไม่มี
    if (!isset($cols['note'])) {
        @$conn->query("ALTER TABLE public_requests ADD COLUMN note VARCHAR(255) DEFAULT NULL AFTER bed_number");
    }
    // เพิ่ม urgency ถ้ายังไม่มี (1..4), default 1 = Walk-in
    if (!isset($cols['urgency'])) {
        @$conn->query("ALTER TABLE public_requests ADD COLUMN urgency TINYINT(1) NOT NULL DEFAULT 1 AFTER status");
    }
    // เพิ่ม patient_age / patient_gender / user_rh / updated_at ถ้ายังไม่มี
    if (!isset($cols['patient_age'])) {
        @$conn->query("ALTER TABLE public_requests ADD COLUMN patient_age INT DEFAULT NULL AFTER urgency");
    }
    if (!isset($cols['patient_gender'])) {
        @$conn->query("ALTER TABLE public_requests ADD COLUMN patient_gender VARCHAR(32) DEFAULT NULL AFTER patient_age");
    }
    if (!isset($cols['user_rh'])) {
        @$conn->query("ALTER TABLE public_requests ADD COLUMN user_rh VARCHAR(255) DEFAULT NULL AFTER patient_gender");
    }
    if (!isset($cols['updated_at'])) {
        @$conn->query("ALTER TABLE public_requests ADD COLUMN updated_at DATETIME NULL AFTER created_at");
    }

    // ensure queue_no and queue_date columns exist for daily-resettable visible queue numbers
    $rq = $conn->query("SHOW COLUMNS FROM public_requests LIKE 'queue_no'");
    if (!$rq || $rq->num_rows === 0) {
        @$conn->query("ALTER TABLE public_requests ADD COLUMN queue_no INT DEFAULT NULL AFTER id");
    }
    if ($rq)
        $rq->free();
    $rq2 = $conn->query("SHOW COLUMNS FROM public_requests LIKE 'queue_date'");
    if (!$rq2 || $rq2->num_rows === 0) {
        @$conn->query("ALTER TABLE public_requests ADD COLUMN queue_date DATE DEFAULT NULL AFTER queue_no");
    }
    if ($rq2)
        $rq2->free();

    // ensure original_queue_no and waiting_queue_no exist (original_queue_no = immutable historical value,
    // waiting_queue_no = current waiting/ordering number which can be renumbered)
    $r_orig = $conn->query("SHOW COLUMNS FROM public_requests LIKE 'original_queue_no'");
    if (!$r_orig || $r_orig->num_rows === 0) {
        @$conn->query("ALTER TABLE public_requests ADD COLUMN original_queue_no INT DEFAULT NULL AFTER queue_no");
    }
    if ($r_orig)
        $r_orig->free();

    $r_wait = $conn->query("SHOW COLUMNS FROM public_requests LIKE 'waiting_queue_no'");
    if (!$r_wait || $r_wait->num_rows === 0) {
        @$conn->query("ALTER TABLE public_requests ADD COLUMN waiting_queue_no INT DEFAULT NULL AFTER original_queue_no");
    }
    if ($r_wait)
        $r_wait->free();

    // ensure status enum includes required values
    $needModify = false;
    if ($statusType === null) {
        $needModify = true;
    } else {
        if (stripos($statusType, "'confirmed'") === false || stripos($statusType, "'done'") === false || stripos($statusType, "'canceled'") === false) {
            $needModify = true;
        }
    }
    if ($needModify) {
        @$conn->query("ALTER TABLE public_requests MODIFY COLUMN status ENUM('pending','confirmed','done','canceled','handled') NOT NULL DEFAULT 'pending'");
    }
}


// --- helper: decide display name for user_rh / booked_by / cancelled_by ---
function resolveUserRhDisplay($raw, $fullname)
{
    $raw = trim((string) $raw);
    $fullname = trim((string) $fullname);
    $except = ['Patient', 'PLAPLAPLA@gmail.com', 'Nurse.1@gmail.com'];
    if ($raw !== '' && in_array($raw, $except, true))
        return $raw;
    if ($fullname !== '')
        return $fullname;
    return $raw !== '' ? $raw : '-';
}

// --- เพิ่ม helper เพื่อสร้าง/ยืนยันตาราง cancellations ---
function ensureCancellationsTable($conn)
{
    $create = "
    CREATE TABLE IF NOT EXISTS cancellations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        public_request_id INT DEFAULT NULL,
        hn VARCHAR(64) DEFAULT NULL,
        patient_name VARCHAR(255) DEFAULT NULL,
        phone VARCHAR(32) DEFAULT NULL,
        bed_number INT DEFAULT NULL,
        urgency TINYINT(1) DEFAULT NULL,
        note TEXT DEFAULT NULL,
        cancelled_by VARCHAR(255) DEFAULT NULL,
        user_id_cb INT DEFAULT NULL,
        cancelled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        booked_by VARCHAR(255) DEFAULT NULL,
        user_id_bb INT DEFAULT NULL,
        appointment_at DATETIME DEFAULT NULL,
        KEY idx_user_id_cb (user_id_cb),
        KEY idx_user_id_bb (user_id_bb)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    $resCreate = $conn->query($create);
    if ($resCreate === false) {
        error_log('ensureCancellationsTable create failed: ' . $conn->error);
        // continue best-effort but signal failure
        $create_ok = false;
    } else {
        $create_ok = true;
    }

    // ensure patient_name column exists (best-effort)
    $r = $conn->query("SHOW COLUMNS FROM cancellations LIKE 'patient_name'");
    if (!$r || $r->num_rows === 0) {
        @$conn->query("ALTER TABLE cancellations ADD COLUMN patient_name VARCHAR(255) DEFAULT NULL AFTER hn");
    }
    if ($r)
        $r->free();

    // ensure urgency column exists (best-effort)
    $ru = $conn->query("SHOW COLUMNS FROM cancellations LIKE 'urgency'");
    if (!$ru || $ru->num_rows === 0) {
        @$conn->query("ALTER TABLE cancellations ADD COLUMN urgency TINYINT(1) DEFAULT NULL AFTER bed_number");
    }
    if ($ru)
        $ru->free();

    // ensure user_id_cb column exists (best-effort)
    $rcb = $conn->query("SHOW COLUMNS FROM cancellations LIKE 'user_id_cb'");
    if (!$rcb || $rcb->num_rows === 0) {
        @$conn->query("ALTER TABLE cancellations ADD COLUMN user_id_cb INT DEFAULT NULL AFTER cancelled_by");
    }
    if ($rcb)
        $rcb->free();

    // ensure user_id_bb column exists (best-effort)
    $rbb = $conn->query("SHOW COLUMNS FROM cancellations LIKE 'user_id_bb'");
    if (!$rbb || $rbb->num_rows === 0) {
        @$conn->query("ALTER TABLE cancellations ADD COLUMN user_id_bb INT DEFAULT NULL AFTER booked_by");
    }
    if ($rbb)
        $rbb->free();

    // best-effort: add indexes for new columns (MySQL < 8 has no IF NOT EXISTS)
    $ix1 = $conn->query("SHOW INDEX FROM cancellations WHERE Key_name='idx_user_id_cb'");
    if (!$ix1 || $ix1->num_rows === 0) {
        @$conn->query("CREATE INDEX idx_user_id_cb ON cancellations (user_id_cb)");
    }
    if ($ix1)
        $ix1->free();

    $ix2 = $conn->query("SHOW INDEX FROM cancellations WHERE Key_name='idx_user_id_bb'");
    if (!$ix2 || $ix2->num_rows === 0) {
        @$conn->query("CREATE INDEX idx_user_id_bb ON cancellations (user_id_bb)");
    }
    if ($ix2)
        $ix2->free();
    // ensure we persist visible queue number at time of cancellation
    $rq = $conn->query("SHOW COLUMNS FROM cancellations LIKE 'queue_no'");
    if (!$rq || $rq->num_rows === 0) {
        @$conn->query("ALTER TABLE cancellations ADD COLUMN queue_no INT DEFAULT NULL AFTER public_request_id");
    }
    if ($rq)
        $rq->free();

    // ensure restored_at exists for restore logic
    $restored = $conn->query("SHOW COLUMNS FROM cancellations LIKE 'restored_at'");
    if (!$restored || $restored->num_rows === 0) {
        @$conn->query("ALTER TABLE cancellations ADD COLUMN restored_at DATETIME DEFAULT NULL AFTER cancelled_at");
    }
    if ($restored)
        $restored->free();

    return $create_ok;
}

function pickDisplayName($raw, $fullname)
{
    $raw = trim((string) $raw);
    $fullname = trim((string) $fullname);

    // Preserve sentinel "Patient" (do not replace)
    if ($raw !== '' && strcasecmp($raw, 'patient') === 0)
        return $raw;

    // Prefer fullname if present
    if ($fullname !== '')
        return $fullname;

    return $raw;
}


// --- MAIN EXECUTION ---
// --- MAIN EXECUTION ---

// รีเลขคิวใหม่ทุกครั้งเมื่อข้ามวัน
function resetQueueNumbersIfNewDay($conn) {
    $today = date('Y-m-d');
    // ตรวจสอบว่ามีคิว pending ที่ queue_date < วันนี้ หรือ pending วันนี้
    $sqlCheck = "SELECT COUNT(*) AS cnt FROM public_requests WHERE (status = 'pending' AND queue_date < ?) OR (status = 'pending' AND queue_date = ?)";
    $stmtCheck = $conn->prepare($sqlCheck);
    if ($stmtCheck) {
        $stmtCheck->bind_param('ss', $today, $today);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();
        $row = $resCheck->fetch_assoc();
        $count = intval($row['cnt'] ?? 0);
        $stmtCheck->close();
        if ($count > 0) {
            allocateNextDailyQueueNo($conn);
        }
    }
}

// เรียกรีคิวทุกครั้งที่มี request
resetQueueNumbersIfNewDay($conn);

try {
    // If GET: support action=get_pttype&hn=... else return bed list

    // determine requested action (GET param or default POST action later)
    $reqAction = $_REQUEST['action'] ?? null;

    // Actions that only need HOSxP (no local tables): get_pttype
    $hosxpOnly = ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_pttype');

    // For operations that rely on local ICU DB structures (beds, patients, bed_bookings),
    // validate tables exist and return helpful JSON if missing.
    $localTableChecksNeeded = true;
    if ($hosxpOnly)
        $localTableChecksNeeded = false;

    if ($localTableChecksNeeded) {
        // list of critical tables for booking flow
        $critical = ['beds', 'patients', 'bed_bookings'];
        // public_requests is created/ensured by ensurePublicRequestsTable when needed
        $missing = checkRequiredTables($conn, $critical);
        if (!empty($missing)) {
            echo json_encode([
                'success' => false,
                'message' => 'ตารางฐานข้อมูลที่ระบบต้องการหายไป: ' . implode(', ', $missing),
                'missing_tables' => $missing,
                'hint' => 'โปรดนำเข้าโครงสร้างฐานข้อมูล (เช่นไฟล์ SQL) หรือสร้างตารางที่ขาดก่อน'
            ]);
            exit;
        }
    }

    // determine authentication
    $isAuthenticated = isset($_SESSION['user_id']) && intval($_SESSION['user_id']) > 0;

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // --- public requests: คืนรายการคำขอของผู้ป่วยตาม HN ---
        if (isset($_GET['action']) && $_GET['action'] === 'get_public_requests_by_hn') {
            // allow admin OR staff to query public requests by HN
            if (!($isAdmin || $isStaff)) {
                echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อนเพื่อดูคำขอ']);
                exit;
            }
            $hn = isset($_GET['hn']) ? trim($_GET['hn']) : '';
            if ($hn === '') {
                echo json_encode(['success' => false, 'message' => 'กรุณาระบุ HN']);
                exit;
            }
            ensurePublicRequestsTable($conn);
            // Only return non-canceled requests
            $stmt = $conn->prepare("SELECT pr.id, pr.queue_no, pr.queue_date, pr.hn, pr.patient_name, pr.phone, pr.bed_number, pr.status, pr.urgency, pr.created_at, pr.updated_at, pr.note, pr.patient_age, pr.patient_gender, pr.user_rh, pr.original_queue_no, pr.waiting_queue_no, pr.original_queue_no AS admit_seq, u.fullname AS user_rh_fullname FROM public_requests pr LEFT JOIN users u ON u.email = (pr.user_rh COLLATE utf8mb4_unicode_ci) WHERE hn = ? AND pr.status <> 'canceled' ORDER BY created_at DESC LIMIT 200");
            if (!$stmt) {
                echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                exit;
            }
            $stmt->bind_param('s', $hn);
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = [];
            while ($r = $res->fetch_assoc()) {
                $r['booked_by_display'] = pickDisplayName($r['booked_by'] ?? '', $r['booked_by_name'] ?? '');
                $r['cancelled_by_display'] = pickDisplayName($r['cancelled_by'] ?? '', $r['cancelled_by_name'] ?? '');
                $rows[] = $r;
            }
            $stmt->close();

            // add display name for booker (user_rh)
            foreach ($rows as &$rr) {
                $raw = isset($rr['user_rh']) ? $rr['user_rh'] : '';
                $fn = isset($rr['user_rh_fullname']) ? $rr['user_rh_fullname'] : '';
                $rr['user_rh_display'] = resolveUserRhDisplay($raw, $fn);
            }
            unset($rr);
            echo json_encode(['success' => true, 'requests' => $rows]);
            exit;
        }
        // รองรับการขอข้อมูลสิทธิ์การรักษาจาก HOSxP: ?action=get_pttype&hn=12345
        if (isset($_GET['action']) && $_GET['action'] === 'get_pttype') {
            $hn = isset($_GET['hn']) ? preg_replace('/\D/', '', $_GET['hn']) : '';
            if ($hn === '') {
                echo json_encode(['success' => false, 'message' => 'กรุณาระบุหมายเลข HN (hn)']);
                exit;
            }
            if (!isset($hosxpConn) || !$hosxpConn) {
                echo json_encode(['success' => false, 'message' => 'ไม่สามารถเชื่อมต่อฐานข้อมูล HOSxP ได้']);
                exit;
            }

            // ดึงข้อมูล pttype.Name ล่าสุดสำหรับ HN (ใช้ SQL ที่ให้มา แต่กรองด้วย pt.hn และเรียง regdate DESC)
            $sql = "
                SELECT
                    a.an,
                    ipt.regdate,
                    ipt.dchdate,
                    pt.hn,
                    pt.fname,
                    pt.lname,
                    w.`name` AS ward_name,
                    a.pttype,
                    pttype.`Name` AS pttype_name
                FROM an_stat a
                INNER JOIN patient pt ON pt.hn = a.hn
                INNER JOIN ipt ON a.an = ipt.an
                INNER JOIN ward w ON w.ward = ipt.ward
                INNER JOIN pttype ON pttype.pttype = a.pttype
                WHERE pt.hn = ?
                ORDER BY ipt.regdate DESC
                LIMIT 1
            ";
            $stmt = $hosxpConn->prepare($sql);
            if (!$stmt) {
                echo json_encode(['success' => false, 'message' => 'ข้อผิดพลาดในการเตรียมคำสั่งฐาน HOSxP: ' . $hosxpConn->error]);
                exit;
            }
            $stmt->bind_param('s', $hn);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                echo json_encode(['success' => true, 'pttype_name' => $row['pttype_name'] ?? '', 'data' => $row]);
            } else {
                echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลสิทธิ์การรักษาสำหรับ HN นี้']);
            }
            $stmt->close();
            exit;
        }

        // NEW: ตรวจสอบโครงสร้าง HOSxP schema (debug)
        if (isset($_GET['action']) && $_GET['action'] === 'inspect_schema') {
            if (!isset($hosxpConn) || !$hosxpConn) {
                echo json_encode(['success' => false, 'message' => 'HOSxP connection failed']);
                exit;
            }

            $schema = [
                'patient_columns' => [],
                'an_stat_columns' => [],
                'ipt_columns' => [],
                'sample_patient_row' => null
            ];

            // Get columns from patient table
            $colRes = $hosxpConn->query("SHOW COLUMNS FROM patient");
            if ($colRes) {
                while ($row = $colRes->fetch_assoc()) {
                    $schema['patient_columns'][] = $row['Field'];
                }
                $colRes->free();
            }

            // Get columns from an_stat table
            $colRes2 = $hosxpConn->query("SHOW COLUMNS FROM an_stat");
            if ($colRes2) {
                while ($row = $colRes2->fetch_assoc()) {
                    $schema['an_stat_columns'][] = $row['Field'];
                }
                $colRes2->free();
            }

            // Get columns from ipt table
            $colRes3 = $hosxpConn->query("SHOW COLUMNS FROM ipt");
            if ($colRes3) {
                while ($row = $colRes3->fetch_assoc()) {
                    $schema['ipt_columns'][] = $row['Field'];
                }
                $colRes3->free();
            }

            // Get sample patient row to see actual structure
            $sampleSql = "SELECT * FROM patient LIMIT 1";
            $sampleRes = $hosxpConn->query($sampleSql);
            if ($sampleRes && $sampleRes->num_rows > 0) {
                $sampleRow = $sampleRes->fetch_assoc();
                $schema['sample_patient_row'] = $sampleRow;
                $sampleRes->free();
            }

            echo json_encode(['success' => true, 'schema' => $schema]);
            exit;
        }

        // NEW: ค้นหาผู้ป่วยจาก CID (13 หลัก) - สำหรับระบบ patient-reserve
        if (isset($_GET['action']) && $_GET['action'] === 'search_by_cid') {
            $cid = isset($_GET['cid']) ? preg_replace('/\D/', '', $_GET['cid']) : '';
            if ($cid === '' || strlen($cid) !== 13) {
                echo json_encode(['success' => false, 'message' => 'กรุณาระบุเลขประจำตัวประชาชน 13 หลัก']);
                exit;
            }
            if (!isset($hosxpConn) || !$hosxpConn) {
                echo json_encode(['success' => false, 'message' => 'ไม่สามารถเชื่อมต่อฐานข้อมูล HOSxP ได้']);
                exit;
            }

            $debugLog = [];
            
            // Check if patient table exists first
            $tableCheck = $hosxpConn->query("SHOW TABLES LIKE 'patient'");
            if (!$tableCheck || $tableCheck->num_rows === 0) {
                $debugLog[] = "ERROR: patient table does not exist";
                echo json_encode(['success' => false, 'found' => false, 'message' => 'ตาราง patient ไม่พบในระบบฐานข้อมูล', '_debug_log' => $debugLog]);
                exit;
            }
            $debugLog[] = "OK: patient table exists";

            // Get actual columns from patient table
            $colCheck = $hosxpConn->query("SHOW COLUMNS FROM patient");
            $actualCols = [];
            if ($colCheck) {
                while ($col = $colCheck->fetch_assoc()) {
                    $actualCols[] = $col['Field'];
                }
                $colCheck->free();
            }
            $debugLog[] = "Patient columns: " . implode(', ', $actualCols);

            // Try direct SELECT * to find by CID (simpler approach - let MySQL find CID in any text column)
            // First, try a simple select to see if we can get any patient
            $patData = null;
            
            // Method 1: Try all common CID column names
            $cidCols = ['citizen_id', 'citizen_id13', 'citizen_id_no', 'cid', 'id_card'];
            foreach ($cidCols as $cidCol) {
                if (in_array($cidCol, $actualCols)) {
                    // Build a safe SELECT with available columns
                    $selectCols = [];
                    foreach (['hn', 'pname', 'fname', 'lname', 'informtel', 'birthday'] as $c) {
                        if (in_array($c, $actualCols)) {
                            $selectCols[] = $c;
                        }
                    }
                    if (empty($selectCols)) $selectCols[] = $cidCol; // fallback
                    
                    $sql = "SELECT " . implode(', ', $selectCols) . " FROM patient WHERE $cidCol = ? LIMIT 1";
                    $debugLog[] = "Trying: $sql";
                    
                    $stmt = $hosxpConn->prepare($sql);
                    if ($stmt) {
                        $stmt->bind_param('s', $cid);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        if ($res && $res->num_rows > 0) {
                            $patData = $res->fetch_assoc();
                            $debugLog[] = "FOUND patient: " . json_encode($patData);
                            $stmt->close();
                            break;
                        }
                        $stmt->close();
                    } else {
                        $debugLog[] = "Prepare failed for col $cidCol: " . $hosxpConn->error;
                    }
                }
            }

            // Method 2: If still not found, try searching HN field directly (in case user enters HN instead of CID)
            if (!$patData && in_array('hn', $actualCols)) {
                $selectCols = [];
                foreach (['hn', 'pname', 'fname', 'lname', 'informtel', 'birthday'] as $c) {
                    if (in_array($c, $actualCols)) {
                        $selectCols[] = $c;
                    }
                }
                if (empty($selectCols)) $selectCols[] = 'hn';
                
                $sql = "SELECT " . implode(', ', $selectCols) . " FROM patient WHERE hn = ? LIMIT 1";
                $debugLog[] = "Fallback trying hn: $sql";
                
                $stmt = $hosxpConn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param('s', $cid);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res && $res->num_rows > 0) {
                        $patData = $res->fetch_assoc();
                        $debugLog[] = "FOUND by hn: " . json_encode($patData);
                        $stmt->close();
                    } else {
                        $debugLog[] = "HN fallback: no rows";
                    }
                    $stmt->close();
                } else {
                    $debugLog[] = "HN fallback prepare failed: " . $hosxpConn->error;
                }
            }

            if ($patData) {
                $hn = $patData['hn'] ?? '';
                $pname = $patData['pname'] ?? '';
                $fname = $patData['fname'] ?? '';
                $lname = $patData['lname'] ?? '';
                $informtel = $patData['informtel'] ?? '';
                $fullname = trim((($fname ?? '') . ' ' . ($lname ?? '')));
                if (!$fullname && !empty($pname)) $fullname = $pname;

                // Calculate age from birthday
                $age = null;
                $birthday = $patData['birthday'] ?? null;
                if ($birthday && $birthday !== '0000-00-00' && trim($birthday) !== '') {
                    try {
                        $bday = new DateTime($birthday);
                        $today = new DateTime();
                        $ageObj = $today->diff($bday);
                        $age = $ageObj->y;
                        $debugLog[] = "Age calculated from birthday: $age years";
                    } catch (Exception $e) {
                        $debugLog[] = "Birthday parse error: " . $e->getMessage();
                    }
                }

                $debugLog[] = "Patient found: hn=$hn, fullname=$fullname, age=$age";

                // ตรวจสอบ admission: match ระหว่าง patient.hn และ an_stat.hn (หา an ที่ยังเปิดอยู่)
                $an = null;
                $wardName = null;
                $debugAnInfo = null;
                
                // Query 1: Check an_stat.hn = patient.hn with active admission (dchdate NULL)
                $anSql = "SELECT a.an, a.hn, ipt.dchdate, w.name AS ward_name FROM an_stat a LEFT JOIN ipt ON a.an = ipt.an LEFT JOIN ward w ON w.ward = ipt.ward WHERE a.hn = ? AND (ipt.dchdate IS NULL OR ipt.dchdate = '' OR ipt.dchdate = '0000-00-00') ORDER BY ipt.regdate DESC LIMIT 1";
                $anStmt = @$hosxpConn->prepare($anSql);
                if ($anStmt) {
                    $anStmt->bind_param('s', $hn);
                    $anStmt->execute();
                    $anRes = $anStmt->get_result();
                    $debugLog[] = "AN Query1 (active only): rows=" . ($anRes ? $anRes->num_rows : 'null');
                    if ($anRes && $anRes->num_rows > 0) {
                        $anRow = $anRes->fetch_assoc();
                        $an = $anRow['an'] ?? null;
                        $wardName = $anRow['ward_name'] ?? null;
                        $debugAnInfo = ['source' => 'active_with_null_dchdate', 'an' => $an, 'dchdate' => $anRow['dchdate']];
                        $debugLog[] = "AN Query1: FOUND (an=$an, dchdate={$anRow['dchdate']})";
                    }
                    $anStmt->close();
                }

                // Query 2: If not found, try without dchdate filter to see if there are ANY an_stat rows (for debug)
                if (!$an) {
                    $anSql2 = "SELECT a.an, a.hn, ipt.dchdate, w.name AS ward_name FROM an_stat a LEFT JOIN ipt ON a.an = ipt.an LEFT JOIN ward w ON w.ward = ipt.ward WHERE a.hn = ? ORDER BY ipt.regdate DESC LIMIT 1";
                    $anStmt2 = @$hosxpConn->prepare($anSql2);
                    if ($anStmt2) {
                        $anStmt2->bind_param('s', $hn);
                        $anStmt2->execute();
                        $anRes2 = $anStmt2->get_result();
                        $debugLog[] = "AN Query2 (all records): rows=" . ($anRes2 ? $anRes2->num_rows : 'null');
                        if ($anRes2 && $anRes2->num_rows > 0) {
                            $anRow2 = $anRes2->fetch_assoc();
                            $dch = isset($anRow2['dchdate']) ? trim((string)$anRow2['dchdate']) : null;
                            $debugAnInfo = ['source' => 'all_records', 'an' => $anRow2['an'] ?? null, 'dchdate_value' => $dch, 'dchdate_is_null' => is_null($dch)];
                            $debugLog[] = "AN Query2: found record (an={$anRow2['an']}, dchdate=$dch, is_null=" . (is_null($dch) ? 'true' : 'false') . ")";
                            // don't set $an here, as dchdate is likely set (discharged)
                        }
                        $anStmt2->close();
                    }
                }

                echo json_encode([
                    'success' => true,
                    'found' => true,
                    'hn' => $hn,
                    'cid' => $cid,
                    'fullname' => $fullname,
                    'fname' => $fname,
                    'lname' => $lname,
                    'age' => $age,
                    'informtel' => $informtel,
                    'has_admission' => $an !== null,
                    'an' => $an,
                    'ward_name' => $wardName,
                    '_debug_log' => $debugLog,
                    '_debug_an_info' => $debugAnInfo
                ]);
                exit;
            } else {
                $debugLog[] = "Patient not found in any SQL query";
            }

            // หากไม่พบในตาราง patient ด้วย CID ให้ลองค้นหา admission โดยตรงใน an_stat (match an_stat.hn กับ CID เป็นทางเลือก)
            // และก็ยังลองใหม่โดยค้นหาทั้ง an_stat และ ipt ว่ามี HN ตรงกับ CID หรือไม่
            $debugLog[] = "Fallback: trying to find admission by CID=$cid";
            
            $directAnSql = "SELECT a.an, a.hn, ipt.dchdate, w.name AS ward_name FROM an_stat a LEFT JOIN ipt ON a.an = ipt.an LEFT JOIN ward w ON w.ward = ipt.ward WHERE a.hn = ? ORDER BY ipt.regdate DESC LIMIT 1";
            $dStmt = @$hosxpConn->prepare($directAnSql);
            if ($dStmt) {
                // bind cid in case some systems store CID in hn column
                $dStmt->bind_param('s', $cid);
                $dStmt->execute();
                $dRes = $dStmt->get_result();
                $debugLog[] = "Fallback an_stat lookup by CID=$cid: rows=" . ($dRes ? $dRes->num_rows : 'null');
                if ($dRes && $dRes->num_rows > 0) {
                    $dRow = $dRes->fetch_assoc();
                    $dch = isset($dRow['dchdate']) ? trim((string)$dRow['dchdate']) : null;
                    $debugLog[] = "Fallback: found (an={$dRow['an']}, dchdate=$dch)";
                    if ($dch === null || $dch === '' || $dch === '0000-00-00') {
                        echo json_encode(['success' => true, 'found' => true, 'hn' => $dRow['hn'] ?? '', 'cid' => $cid, 'fullname' => null, 'informtel' => null, 'has_admission' => true, 'an' => $dRow['an'] ?? null, 'ward_name' => $dRow['ward_name'] ?? null, '_debug_log' => $debugLog]);
                        $dStmt->close();
                        exit;
                    }
                }
                $dStmt->close();
            } else {
                $debugLog[] = "Fallback prepare failed: " . $hosxpConn->error;
            }

            echo json_encode(['success' => false, 'found' => false, 'message' => 'ไม่พบข้อมูลผู้ป่วยที่มีเลขประจำตัวประชาชนนี้', '_debug_log' => $debugLog, 'hint' => 'ใช้ ?action=inspect_schema เพื่อตรวจสอบโครงสร้าง']);
            exit;
        }

        // NEW: ดึงรายการยกเลิกจากตาราง cancellations (admin หรือ staff)
        if (isset($_GET['action']) && $_GET['action'] === 'get_cancellations') {
            if (!($isAdmin || $isStaff)) {
                echo json_encode(['success' => false, 'message' => 'สิทธิ์ไม่เพียงพอ']);
                exit;
            }
            $created = ensureCancellationsTable($conn);
            if (!$created) {
                echo json_encode(['success' => false, 'message' => 'ไม่สามารถเข้าถึงตาราง cancellations: ' . $conn->error]);
                exit;
            }
            $stmt = $conn->prepare("SELECT c.id, c.public_request_id, c.queue_no, c.hn, c.patient_name, c.phone, c.bed_number, c.urgency, c.note, c.cancelled_by, c.user_id_cb, ucb.fullname AS cancelled_by_name, c.cancelled_at, c.booked_by, c.user_id_bb, ubb.fullname AS booked_by_name, c.appointment_at FROM cancellations c LEFT JOIN users ucb ON ucb.user_id = c.user_id_cb LEFT JOIN users ubb ON ubb.user_id = c.user_id_bb ORDER BY c.cancelled_at DESC LIMIT 2000");
            if (!$stmt = $conn->prepare("SELECT c.id, c.public_request_id, c.queue_no, c.hn, c.patient_name, c.phone, c.bed_number, c.urgency, c.note, c.cancelled_by, c.user_id_cb, ucb.fullname AS cancelled_by_name, c.cancelled_at, c.booked_by, c.user_id_bb, ubb.fullname AS booked_by_name, c.appointment_at, c.restored_at FROM cancellations c LEFT JOIN users ucb ON ucb.user_id = c.user_id_cb LEFT JOIN users ubb ON ubb.user_id = c.user_id_bb ORDER BY c.cancelled_at DESC LIMIT 2000")) {
                echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                exit;
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = [];
            while ($r = $res->fetch_assoc())
                $rows[] = $r;
            $stmt->close();
            echo json_encode(['success' => true, 'cancellations' => $rows]);
            exit;
        }

        // คืนรายการ public_requests ทั้งหมด (admin-only)
        if (isset($_GET['action']) && $_GET['action'] === 'get_public_requests') {
            if (!($isAdmin || $isStaff)) {
                echo json_encode(['success' => false, 'message' => 'สิทธิ์ไม่เพียงพอ']);
                exit;
            }
            ensurePublicRequestsTable($conn);
            // Only return non-canceled requests
            $stmt = $conn->prepare("SELECT pr.id, pr.queue_no, pr.queue_date, pr.hn, pr.patient_name, pr.phone, pr.bed_number, pr.status, pr.urgency, pr.created_at, pr.updated_at, pr.note, pr.patient_age, pr.patient_gender, pr.user_rh, pr.original_queue_no, pr.waiting_queue_no, pr.original_queue_no AS admit_seq, u.fullname AS user_rh_fullname FROM public_requests pr LEFT JOIN users u ON u.email = (pr.user_rh COLLATE utf8mb4_unicode_ci) WHERE pr.status <> 'canceled' ORDER BY created_at DESC LIMIT 1000");
            if (!$stmt) {
                echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                exit;
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = [];
            while ($r = $res->fetch_assoc())
                $rows[] = $r;
            $stmt->close();

            // add display name for booker (user_rh)
            foreach ($rows as &$rr) {
                $raw = isset($rr['user_rh']) ? $rr['user_rh'] : '';
                $fn = isset($rr['user_rh_fullname']) ? $rr['user_rh_fullname'] : '';
                $rr['user_rh_display'] = resolveUserRhDisplay($raw, $fn);
            }
            unset($rr);
            echo json_encode(['success' => true, 'requests' => $rows]);
            exit;
        }

        // Admin endpoint: trigger renumbering manually
        if (isset($_GET['action']) && $_GET['action'] === 'renumber_waiting') {
            if (!($isAdmin || $isStaff)) {
                echo json_encode(['success' => false, 'message' => 'สิทธิ์ไม่เพียงพอ']);
                exit;
            }
            ensurePublicRequestsTable($conn);
            $ok = renumberWaitingQueue($conn);
            echo json_encode(['success' => $ok, 'message' => $ok ? 'Renumbered waiting queue' : 'Renumber failed']);
            exit;
        }

        // Get single public_request by id (admin-only)
        if (isset($_GET['action']) && $_GET['action'] === 'get_public_request') {
            if (!($isAdmin || $isStaff)) {
                echo json_encode(['success' => false, 'message' => 'สิทธิ์ไม่เพียงพอ']);
                exit;
            }
            $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'กรุณาระบุ id ของคำขอ']);
                exit;
            }
            ensurePublicRequestsTable($conn);
            // Only return non-canceled requests
            $stmt = $conn->prepare("SELECT pr.id, pr.queue_no, pr.queue_date, pr.hn, pr.patient_name, pr.phone, pr.bed_number, pr.status, pr.urgency, pr.created_at, pr.updated_at, pr.note, pr.patient_age, pr.patient_gender, pr.user_rh, pr.original_queue_no, pr.waiting_queue_no, pr.original_queue_no AS admit_seq, u.fullname AS user_rh_fullname FROM public_requests pr LEFT JOIN users u ON u.email = (pr.user_rh COLLATE utf8mb4_unicode_ci) WHERE id = ? LIMIT 1");
            if (!$stmt) {
                echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                exit;
            }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $raw = isset($row['user_rh']) ? $row['user_rh'] : '';
                $fn = isset($row['user_rh_fullname']) ? $row['user_rh_fullname'] : '';
                $row['user_rh_display'] = resolveUserRhDisplay($raw, $fn);
                echo json_encode(['success' => true, 'request' => $row]);
            } else {
                echo json_encode(['success' => false, 'message' => 'ไม่พบคำขอที่ระบุ']);
            }
            $stmt->close();
            exit;
        }
        // ===================================================
// ⭐ NEW: get_history API (เพิ่มใหม่ทั้งก้อน)
// ===================================================
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_history') {

        header('Content-Type: application/json');

        $rows = [];

        // 1) cancelled
        $r1 = $conn->query("
            SELECT * FROM cancellations
        ");
        if ($r1) {
             while ($row = $r1->fetch_assoc()) {
                $rows[] = $row;
            }
        }

        
        // 2) done (กันซ้ำกับ cancellations)
        $r2 = $conn->query("
        SELECT
             pr.id,
            pr.queue_no,
            pr.hn,
            pr.patient_name,
            pr.phone,
            pr.urgency,
            pr.queue_date AS appointment_at,
            COALESCE(pr.updated_at, pr.created_at) AS cancelled_at,
            pr.user_rh AS booked_by,
            pr.user_rh AS cancelled_by,
            NULL AS restored_at,
            'done' AS status
        FROM public_requests pr
        WHERE pr.status = 'done'
        AND NOT EXISTS (
            SELECT 1
            FROM cancellations c
            WHERE c.public_request_id = pr.id
        )
    ");

            if ($r2) {
                while ($row = $r2->fetch_assoc()) {
                    $rows[] = $row;
                }
            }

            echo json_encode([
                "success" => true,
                "history" => $rows
            ]);
            exit;
    }   

        // ค่า default: คืนรายการเตียง (admin-only)
        if (!$isAdmin) {
            echo json_encode(['success' => false, 'message' => 'สิทธิ์ไม่เพียงพอ']);
            exit;
        }
        $beds = [];
        $res = $conn->query("SELECT bed_id, bed_number, status FROM beds ORDER BY bed_number ASC");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $beds[] = [
                    'bed_id' => (int) $r['bed_id'],
                    'bed_number' => (int) $r['bed_number'],
                    'status' => $r['status']
                ];
            }
        }
        echo json_encode(['success' => true, 'beds' => $beds]);
        exit;
    }

    // POST actions: differentiate cancel vs booking
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // accept JSON body as well as form-encoded POST
        header('Content-Type: application/json');

        $action = $_POST['action'] ?? $_GET['action'] ?? '';
        $raw = file_get_contents('php://input');
        $jsonBody = json_decode($raw, true) ?: [];

        // Support restoration of cancellations via POST JSON or form (admin/staff)
        if ($action === 'restore_cancellation') {

            header('Content-Type: application/json');

            if (!($isAdmin || $isStaff)) {
                echo json_encode(['success' => false, 'error' => 'permission_denied']);
                exit;
            }

            $id = 0;
            if (isset($_POST['id']))
                $id = intval($_POST['id']);
            elseif (isset($jsonBody['id']))
                $id = intval($jsonBody['id']);
            elseif (isset($_REQUEST['id']))
                $id = intval($_REQUEST['id']);

            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'missing_id']);
                exit;
            }

            $created = ensureCancellationsTable($conn);
            if (!$created) {
                echo json_encode(['success' => false, 'error' => 'cannot_access_cancellations_table', 'message' => 'ไม่สามารถเข้าถึงตาราง cancellations: ' . $conn->error]);
                exit;
            }

            $stmt = $conn->prepare("SELECT * FROM cancellations WHERE id = ? LIMIT 1");
            if (!$stmt) {
                echo json_encode(['success' => false, 'error' => 'db_prepare_failed']);
                exit;
            }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if (!$row) {
                echo json_encode(['success' => false, 'error' => 'not_found']);
                exit;
            }

            // ⭐ กันกู้ซ้ำ
            if (!empty($row['restored_at'])) {
                echo json_encode([
                    'success' => false,
                    'error' => 'already_restored'
                ]);
                exit;
            }


            // Mark this cancellation as restored (set restored_at)
            $updateRestored = $conn->prepare("UPDATE cancellations SET restored_at = NOW() WHERE id = ?");
            if ($updateRestored) {
                $updateRestored->bind_param('i', $id);
                $updateRestored->execute();
                $updateRestored->close();
            }

            // If this cancellation references an existing public_request, update its status to pending and move to end of queue
            if (!empty($row['public_request_id'])) {
                $prId = intval($row['public_request_id']);
                // หา queue_no และ waiting_queue_no สูงสุดของวันนี้
                $maxQ = 1;
                $maxW = 1;
                $qres = $conn->query("SELECT MAX(queue_no) AS mq, MAX(waiting_queue_no) AS mw FROM public_requests WHERE queue_date = CURDATE()");
                if ($qres) {
                    $qr = $qres->fetch_assoc();
                    $maxQ = intval($qr['mq'] ?? 0) + 1;
                    $maxW = intval($qr['mw'] ?? 0) + 1;
                    $qres->free();
                }
                $u = $conn->prepare("UPDATE public_requests SET status = 'pending',updated_at = NOW(),queue_date = CURDATE()WHERE id = ?");
                if ($u) {
                    $u->bind_param('i', $prId);
                    $u->execute();
                    $u->close();
                }
                // return id and timestamp for client
                echo json_encode(['success' => true, 'restored_to_public_request_id' => $prId, 'created_at' => date('Y-m-d H:i:s')]);
                exit;
            }

            // Otherwise insert a new public_requests row and return its id (queue_no/queue_date/created_at = วันนี้, ไปท้ายสุด)
            ensurePublicRequestsTable($conn);
            $hn = $row['hn'] ?? '';
            $pname = $row['patient_name'] ?? $row['name'] ?? '';
            $phone = $row['phone'] ?? '';
            $bed_number = isset($row['bed_number']) ? intval($row['bed_number']) : null;
            $urgency = normalizeUrgency(isset($row['urgency']) ? $row['urgency'] : null, $row['appointment_at'] ?? null);
            $note = $row['note'] ?? '';
            // หา queue_no และ waiting_queue_no สูงสุดของวันนี้
            $maxQ = 1;
            $maxW = 1;
            $qres = $conn->query("SELECT MAX(queue_no) AS mq, MAX(waiting_queue_no) AS mw FROM public_requests WHERE queue_date = CURDATE()");
            if ($qres) {
                $qr = $qres->fetch_assoc();
                $maxQ = intval($qr['mq'] ?? 0) + 1;
                $maxW = intval($qr['mw'] ?? 0) + 1;
                $qres->free();
            }
            $ins = $conn->prepare("INSERT INTO public_requests (queue_no, queue_date, hn, patient_name, phone, bed_number, note, status, urgency, user_rh, created_at, waiting_queue_no) VALUES (?, CURDATE(), ?, ?, ?, ?, ?, 'pending', ?, ?, NOW(), ?)");
            if (!$ins) {
                echo json_encode(['success' => false, 'error' => 'db_prepare_failed_insert']);
                exit;
            }
            $user_rh = !empty($row['booked_by']) ? $row['booked_by'] : 'Patient';
            $ins->bind_param('isssisisii', $maxQ, $hn, $pname, $phone, $bed_number, $note, $urgency, $user_rh, $maxW);
            $ok = $ins->execute();
            $newId = $ins->insert_id;
            $ins->close();
            if ($ok) {
                echo json_encode(['success' => true, 'public_request_id' => $newId, 'created_at' => date('Y-m-d H:i:s')]);
                exit;
            }

            echo json_encode(['success' => false, 'error' => 'insert_failed']);
            exit;
        }

        // POST action: carry over stale pending queues from previous dates into today
        if ($action === 'carry_over_stale_queues') {
            if (!($isAdmin || $isStaff)) {
                echo json_encode(['success' => false, 'error' => 'permission_denied']);
                exit;
            }
            ensurePublicRequestsTable($conn);

            // select pending requests with queue_date before today
            $sel = $conn->prepare("SELECT id FROM public_requests WHERE status = 'pending' AND queue_date < CURDATE() ORDER BY queue_date ASC, id ASC");
            if (!$sel) {
                echo json_encode(['success' => false, 'error' => 'db_prepare_failed']);
                exit;
            }
            $sel->execute();
            $res = $sel->get_result();
            $staleIds = [];
            while ($r = $res->fetch_assoc())
                $staleIds[] = intval($r['id']);
            $sel->close();

            $count = count($staleIds);
            if ($count === 0) {
                echo json_encode(['success' => true, 'moved' => 0]);
                exit;
            }

            // หา max waiting_queue_no จากทุกวัน (ไม่แยกวัน)
            $maxWaiting = 0;
            $qres = $conn->query("SELECT MAX(waiting_queue_no) AS mw FROM public_requests");
            if ($qres) {
                $qr = $qres->fetch_assoc();
                $maxWaiting = intval($qr['mw'] ?? 0);
                $qres->free();
            }

            $conn->begin_transaction();
            // shift existing today's queue numbers up by $count (only non-null queue_no)
            $shiftSql = "UPDATE public_requests SET queue_no = queue_no + " . intval($count) . " WHERE queue_date = CURDATE() AND queue_no IS NOT NULL";
            if ($conn->query($shiftSql) === false) {
                $conn->rollback();
                echo json_encode(['success' => false, 'error' => 'shift_failed', 'message' => $conn->error]);
                exit;
            }

            $upd = $conn->prepare("UPDATE public_requests SET queue_date = CURDATE(), queue_no = ?, waiting_queue_no = ?, updated_at = NOW() WHERE id = ?");
            if (!$upd) {
                $conn->rollback();
                echo json_encode(['success' => false, 'error' => 'db_prepare_failed_update']);
                exit;
            }
            // waiting_queue_no starts from maxWaiting+1 and increases
            $i = 1;
            foreach ($staleIds as $pid) {
                $waitingNo = $maxWaiting + $i;
                $upd->bind_param('iii', $i, $waitingNo, $pid);
                if (!$upd->execute()) {
                    $upd->close();
                    $conn->rollback();
                    echo json_encode(['success' => false, 'error' => 'update_failed', 'id' => $pid, 'message' => $conn->error]);
                    exit;
                }
                $i++;
            }
            $upd->close();
            $conn->commit();

            // หลัง carry over ให้เรียก renumberWaitingQueue() เพื่อให้ W-xxx ต่อเนื่องทั่วระบบ (เรียง created_at)
            renumberWaitingQueue($conn);
            echo json_encode(['success' => true, 'moved' => $count]);
            exit;
        }
        // restrict all management actions to admin; allow 'public_request' from anonymous/public
        // Allow staff to perform management actions as well (manage_request / cancel etc.)
        if ($action !== 'public_request' && !($isAdmin || $isStaff)) {
            echo json_encode(['success' => false, 'message' => 'สิทธิ์ไม่เพียงพอ']);
            exit;
        }

        // Get booking/patient info for a bed
        if ($action === 'get_booking') {
            $bedNumber = intval($_POST['bed_number'] ?? 0);
            if ($bedNumber <= 0) {
                echo json_encode(['success' => false, 'message' => 'ข้อมูลเตียงไม่ถูกต้อง']);
                exit;
            }

            // 1) หา booking ล่าสุดของเตียงนี้ (เฉพาะ active) เพื่อเอา "เวลาเข้าเตียง"
            $stmtB = $conn->prepare("
                SELECT bb.booking_id, bb.booking_date, bb.patient_id, b.bed_id
                FROM bed_bookings bb
                JOIN beds b ON bb.bed_id = b.bed_id
                WHERE b.bed_number = ?
                  AND bb.status = 'active'
                ORDER BY bb.booking_date DESC
                LIMIT 1
            ");
            if (!$stmtB) {
                echo json_encode(['success' => false, 'message' => 'ข้อผิดพลาดในการเตรียมคำสั่ง booking: ' . $conn->error]);
                exit;
            }
            $stmtB->bind_param("i", $bedNumber);
            $stmtB->execute();
            $resB = $stmtB->get_result();
            if (!$resB || $resB->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'ไม่พบการจองสำหรับเตียงนี้']);
                $stmtB->close();
                exit;
            }
            $bk = $resB->fetch_assoc();
            $stmtB->close();

            $patientId = intval($bk['patient_id'] ?? 0);
            $bedInAt = $bk['booking_date'] ?? null;

            // 2) หา HN/ข้อมูลคนไข้จาก public_requests ของเตียงนี้ (ยกเว้น canceled) เอารายการล่าสุด
            $hn = '';
            $prPatientName = '';
            $prAge = 0;
            $prGender = '';
            $prNote = '';

            $stmtPR = $conn->prepare("
                SELECT id, hn, patient_name, patient_age, patient_gender, note, status, created_at, updated_at
                FROM public_requests
                WHERE bed_number = ?
                  AND status <> 'canceled'
                ORDER BY COALESCE(updated_at, created_at) DESC
                LIMIT 1
            ");
            if ($stmtPR) {
                $stmtPR->bind_param("i", $bedNumber);
                $stmtPR->execute();
                $resPR = $stmtPR->get_result();
                if ($resPR && $resPR->num_rows > 0) {
                    $pr = $resPR->fetch_assoc();
                    $hn = trim((string) ($pr['hn'] ?? ''));
                    $prPatientName = trim((string) ($pr['patient_name'] ?? ''));
                    $prAge = intval($pr['patient_age'] ?? 0);
                    $prGender = trim((string) ($pr['patient_gender'] ?? ''));
                    $prNote = trim((string) ($pr['note'] ?? ''));
                }
                $stmtPR->close();
            }

            // เตรียม payload (เน้น HN + เวลาเข้าเตียง)
            $data = [
                'hn' => $hn,
                'patient_name' => $prPatientName,
                'age' => $prAge,
                'gender' => $prGender,
                'note' => $prNote,
                'bed_in_at' => $bedInAt,
                'booking_id' => intval($bk['booking_id'] ?? 0),
                'patient_id' => $patientId,
                'bed_number' => $bedNumber
            ];

            // 3) (ทางเลือก) ดึงข้อมูลจาก HOSxP โดยตรง ถ้าเชื่อมต่อได้และมี HN
            $hnDigits = preg_replace('/\D+/', '', $hn);
            if ($hnDigits !== '') {
                $hnDigits = str_pad(substr($hnDigits, -9), 9, '0', STR_PAD_LEFT);
                $data['hn'] = $hnDigits;
            }

            if (!empty($data['hn']) && isset($hosxpConn) && $hosxpConn instanceof mysqli) {
                $stmtH = $hosxpConn->prepare("
                    SELECT hn, pname, fname, lname, sex, birthday
                    FROM patient
                    WHERE hn = ?
                    LIMIT 1
                ");
                if ($stmtH) {
                    $stmtH->bind_param('s', $data['hn']);
                    $stmtH->execute();
                    $resH = $stmtH->get_result();
                    if ($resH && $resH->num_rows > 0) {
                        $h = $resH->fetch_assoc();

                        $fullName = trim(
                            trim((string) ($h['pname'] ?? '') . ' ' . ($h['fname'] ?? '') . ' ' . ($h['lname'] ?? ''))
                        );

                        $sex = trim((string) ($h['sex'] ?? ''));
                        $genderThai = '';
                        if ($sex === '1')
                            $genderThai = 'ชาย';
                        elseif ($sex === '2')
                            $genderThai = 'หญิง';

                        $ageHos = 0;
                        $bd = trim((string) ($h['birthday'] ?? ''));
                        if ($bd !== '') {
                            try {
                                $dob = new DateTime($bd);
                                $now = new DateTime();
                                $ageHos = (int) $dob->diff($now)->y;
                            } catch (Exception $e) {
                                $ageHos = 0;
                            }
                        }

                        if ($fullName !== '')
                            $data['patient_name'] = $fullName;
                        if ($genderThai !== '')
                            $data['gender'] = $genderThai;
                        if ($ageHos > 0)
                            $data['age'] = $ageHos;
                    }
                    $stmtH->close();
                }
            }

            // 4) Fallback: ถ้า HOSxP ไม่ได้/ไม่มีข้อมูล ให้ใช้ตาราง patients เดิม (เพื่อไม่ให้ระบบเดิมพัง)
            if ($patientId > 0 && (empty($data['patient_name']) || empty($data['gender']) || intval($data['age']) <= 0 || empty($data['note']))) {
                $stmtP = $conn->prepare("SELECT patient_name, age, gender, note FROM patients WHERE patient_id = ? LIMIT 1");
                if ($stmtP) {
                    $stmtP->bind_param('i', $patientId);
                    $stmtP->execute();
                    $resP = $stmtP->get_result();
                    if ($resP && $resP->num_rows > 0) {
                        $p = $resP->fetch_assoc();
                        if (empty($data['patient_name']))
                            $data['patient_name'] = trim((string) ($p['patient_name'] ?? ''));
                        if (empty($data['gender']))
                            $data['gender'] = trim((string) ($p['gender'] ?? ''));
                        if (intval($data['age']) <= 0)
                            $data['age'] = intval($p['age'] ?? 0);
                        if (empty($data['note']))
                            $data['note'] = trim((string) ($p['note'] ?? ''));
                    }
                    $stmtP->close();
                }
            }

            echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
            exit;
        }


        // Update patient info for an existing booking
        if ($action === 'update') {
            $patientId = intval($_POST['patient_id'] ?? 0);
            $patientName = trim($_POST['patient_name'] ?? '');
            $age = intval($_POST['age'] ?? 0);
            $gender = trim($_POST['gender'] ?? '');
            $disease = trim($_POST['disease'] ?? '');
            $painScore = trim($_POST['pain_score'] ?? '');
            $note = trim($_POST['note'] ?? '');

            if ($patientId <= 0 || empty($patientName) || $age <= 0 || empty($gender)) {
                echo json_encode(['success' => false, 'message' => 'ข้อมูลสำหรับการอัปเดตไม่ครบถ้วน']);
                exit;
            }

            $up = $conn->prepare("UPDATE patients SET patient_name = ?, age = ?, gender = ?, disease = ?, pain_score = ?, note = ? WHERE patient_id = ?");
            if (!$up) {
                echo json_encode(['success' => false, 'message' => 'ข้อผิดพลาดในการเตรียมคำสั่ง: ' . $conn->error]);
                exit;
            }
            // types: s i s s s s i -> "sissssi"
            $up->bind_param("sissssi", $patientName, $age, $gender, $disease, $painScore, $note, $patientId);
            if (!$up->execute()) {
                echo json_encode(['success' => false, 'message' => 'ข้อผิดพลาดในการอัปเดตข้อมูลผู้ป่วย: ' . $up->error]);
                exit;
            }
            $up->close();
            echo json_encode(['success' => true, 'message' => 'แก้ไขข้อมูลผู้ป่วยเรียบร้อยแล้ว']);
            exit;
        }

        // Cancel booking: free up the bed (delete bed_bookings rows and set bed status)
        if ($action === 'cancel') {
            $bedNumber = intval($_POST['bed_number'] ?? 0);
            if ($bedNumber <= 0) {
                echo json_encode(['success' => false, 'message' => 'ข้อมูลเตียงไม่ถูกต้อง']);
                exit;
            }

            // ✅ NEW: เมื่อแอดมิน/เจ้าหน้าที่กด “ยกเลิกเตียง” (action=cancel)
            // ให้บันทึกประวัติลงตาราง cancellations ด้วย เพื่อให้หน้า Cancellation history.html แสดงผลได้
            // (เดิมบันทึกเฉพาะตอน action=manage_request เปลี่ยนสถานะเป็น canceled)
            $audit_cancellation_inserted = null;
            $audit_cancellation_error = null;
            $cancel_snapshot = null;
            try {
                ensurePublicRequestsTable($conn);
                $created = ensureCancellationsTable($conn);
                if (!$created) {
                    $audit_cancellation_error = 'ไม่สามารถเข้าถึง/สร้างตาราง cancellations: ' . $conn->error;
                    error_log($audit_cancellation_error);
                }

                // ดึงข้อมูลคำขอล่าสุดของเตียงนี้ (ถ้ามี) เพื่อนำไปเก็บใน cancellations
                $qpr = $conn->prepare("\\
                                        SELECT id, queue_no, hn, patient_name, phone, bed_number, note, urgency, user_rh, created_at, updated_at
                                        FROM public_requests
                                        WHERE bed_number = ?
                                            AND status <> 'canceled'
                                        ORDER BY COALESCE(updated_at, created_at) DESC
                                        LIMIT 1\
                                ");
                if ($qpr) {
                    $qpr->bind_param('i', $bedNumber);
                    $qpr->execute();
                    $rpr = $qpr->get_result();
                    if ($rpr && $rpr->num_rows > 0) {
                        $cancel_snapshot = $rpr->fetch_assoc();
                    }
                    $qpr->close();
                }

                // ถ้ามี public_request ที่อ้างอิง ให้กัน insert ซ้ำ (เช็คด้วย public_request_id)
                $already = false;
                if (!empty($cancel_snapshot['id'])) {
                    $chk = $conn->prepare("SELECT id FROM cancellations WHERE public_request_id = ? LIMIT 1");
                    if ($chk) {
                        $pid = intval($cancel_snapshot['id']);
                        $chk->bind_param('i', $pid);
                        $chk->execute();
                        $rr = $chk->get_result();
                        if ($rr && $rr->num_rows > 0)
                            $already = true;
                        $chk->close();
                    }
                }

                if (!$already) {
                    $prId = !empty($cancel_snapshot['id']) ? intval($cancel_snapshot['id']) : null;
                    $hn = $cancel_snapshot['hn'] ?? '';
                    $pname = $cancel_snapshot['patient_name'] ?? $row['name'] ?? '';
                    $phone = $cancel_snapshot['phone'] ?? '';
                    $note = $cancel_snapshot['note'] ?? '';
                    $qnum_from_snapshot = isset($cancel_snapshot['queue_no']) ? intval($cancel_snapshot['queue_no']) : 0;
                    $booked_by = $cancel_snapshot['user_rh'] ?? null;
                    $appointment_at = $cancel_snapshot['created_at'] ?? null;

                    // ปรับ urgency ให้อยู่ใน 1/2 เสมอ
                    $urgency_val = normalizeUrgency($cancel_snapshot['urgency'] ?? null, $appointment_at);

                    // ผู้ยกเลิก (audit) ต้องมาจาก token/session เท่านั้น
                    $cancelledBy = getCurrentAdminEmailForAudit($tokenPayload) ?? 'Unknown';
                    $cancelled_user_id = getCurrentUserIdForAudit($conn, $tokenPayload);
                    $booked_user_id = resolveUserIdFromIdentifier($conn, $booked_by);

                    // ถ้าชื่อว่าง ให้เก็บเป็น NULL (เพื่อความสวยงามในหน้า history)
                    if ($pname === '')
                        $pname = null;

                    $insC = $conn->prepare(
                        "INSERT INTO cancellations (public_request_id, queue_no, hn, patient_name, phone, bed_number, urgency, note, cancelled_by, user_id_cb, cancelled_at, booked_by, user_id_bb, appointment_at, status)
                         VALUES (NULLIF(?,0, status, 'canceled'), NULLIF(?,0), ?, ?, ?, NULLIF(?,0), ?, ?, ?, NULLIF(?,0), NOW(), ?, NULLIF(?,0), ?)"
                    );
                    if ($insC) {
                        $prId_i = $prId ? intval($prId) : 0;
                        $bednum_i = $bedNumber > 0 ? $bedNumber : 0;
                        $cancelled_uid_i = $cancelled_user_id ? intval($cancelled_user_id) : 0;
                        $booked_uid_i = $booked_user_id ? intval($booked_user_id) : 0;
                        $insC->bind_param(
                            'iisssiissisis',
                            $prId_i,
                            $qnum_from_snapshot,
                            $hn,
                            $pname,
                            $phone,
                            $bednum_i,
                            $urgency_val,
                            $note,
                            $cancelledBy,
                            $cancelled_uid_i,
                            $booked_by,
                            $booked_uid_i,
                            $appointment_at
                        );
                        if (!$insC->execute()) {
                            $audit_cancellation_inserted = false;
                            $audit_cancellation_error = $insC->error ?: 'insert failed';
                            error_log('cancellations insert (action=cancel) failed: ' . $audit_cancellation_error);
                        } else {
                            $audit_cancellation_inserted = true;
                        }
                        $insC->close();
                    } else {
                        $audit_cancellation_inserted = false;
                        $audit_cancellation_error = $conn->error ?: 'prepare failed';
                        error_log('cancellations prepare (action=cancel) failed: ' . $audit_cancellation_error);
                    }
                } else {
                    $audit_cancellation_inserted = true; // already exists
                }

                // ถ้าเจอ public_request ให้เปลี่ยนสถานะเป็น canceled ด้วย (กันคิวค้าง)
                if (!empty($cancel_snapshot['id'])) {
                    $pid = intval($cancel_snapshot['id']);
                    $uu = $conn->prepare("UPDATE public_requests SET status='canceled', updated_at=NOW() WHERE id=? LIMIT 1");
                    if ($uu) {
                        $uu->bind_param('i', $pid);
                        @$uu->execute();
                        $uu->close();
                    }
                }
            } catch (Throwable $ee) {
                // ไม่ให้กระทบ flow ยกเลิกเตียง (audit เป็น best-effort)
                $audit_cancellation_inserted = false;
                $audit_cancellation_error = $ee->getMessage();
                error_log('audit cancellation (action=cancel) error: ' . $ee->getMessage());
            }

            // find bed_id
            $bstmt = $conn->prepare("SELECT bed_id FROM beds WHERE bed_number = ? LIMIT 1");
            if (!$bstmt)
                throw new Exception('ข้อผิดพลาดในการเตรียมคำสั่ง: ' . $conn->error);
            $bstmt->bind_param("i", $bedNumber);
            $bstmt->execute();
            $bres = $bstmt->get_result();
            if ($bres->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'ไม่พบเตียงดังกล่าว']);
                exit;
            }
            $brow = $bres->fetch_assoc();
            $bedId = (int) $brow['bed_id'];
            $bstmt->close();

            // transaction: delete bookings for that bed and update bed status
            $conn->begin_transaction();
            $del = $conn->prepare("DELETE FROM bed_bookings WHERE bed_id = ?");
            if (!$del) {
                $conn->rollback();
                throw new Exception('ข้อผิดพลาดในการเตรียมคำสั่งลบ: ' . $conn->error);
            }
            $del->bind_param("i", $bedId);
            if (!$del->execute()) {
                $conn->rollback();
                throw new Exception('ข้อผิดพลาดในการยกเลิกการจอง: ' . $del->error);
            }
            $del->close();

            $upd = $conn->prepare("UPDATE beds SET status = 'available' WHERE bed_id = ?");
            if (!$upd) {
                $conn->rollback();
                throw new Exception('ข้อผิดพลาดในการเตรียมคำสั่งอัปเดต: ' . $conn->error);
            }
            $upd->bind_param("i", $bedId);
            if (!$upd->execute()) {
                $conn->rollback();
                throw new Exception('ข้อผิดพลาดในการอัปเดตสถานะเตียง: ' . $upd->error);
            }
            $upd->close();

            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => 'ยกเลิกการจองเรียบร้อยแล้ว',
                // debug-friendly fields (ไม่กระทบหน้าเดิม)
                'cancellation_inserted' => $audit_cancellation_inserted,
                'cancellation_error' => $audit_cancellation_error
            ]);
            exit;
        }

        // Manage public request status (confirm / done / cancel)
        if ($action === 'manage_request') {
            $id = intval($_POST['id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            $allowed = ['pending', 'confirmed', 'done', 'canceled', 'handled'];
            if ($id <= 0 || !in_array($status, $allowed, true)) {
                echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ถูกต้องสำหรับการจัดการคำขอ']);
                exit;
            }

            // ดึงแถวเดิมก่อนอัปเดต (ใช้สำหรับบันทึก cancellations)
            $old = null;
            $sel = $conn->prepare("SELECT id, queue_no, hn, patient_name, phone, bed_number, note, urgency, user_rh, created_at FROM public_requests WHERE id = ? LIMIT 1");
            if ($sel) {
                $sel->bind_param('i', $id);
                $sel->execute();
                $resSel = $sel->get_result();
                if ($resSel && $resSel->num_rows > 0)
                    $old = $resSel->fetch_assoc();
                $sel->close();
            }

            // เพิ่ม: ถ้าเปลี่ยนเป็น done หรือ canceled ให้บันทึกเวลาปัจจุบันลง updated_at
            if ($status === 'done' || $status === 'canceled') {
                $u = $conn->prepare("UPDATE public_requests SET status = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
                if (!$u) {
                    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                    exit;
                }
                $u->bind_param('si', $status, $id);
            } else {
                $u = $conn->prepare("UPDATE public_requests SET status = ? WHERE id = ? LIMIT 1");
                if (!$u) {
                    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                    exit;
                }
                $u->bind_param('si', $status, $id);
            }

            if ($u->execute()) {
                // ถ้าเป็นการยกเลิก ให้บันทึกลงตาราง cancellations (ถ้ายังไม่มี)
                $cancellation_inserted = false;
                $cancellation_error = null;
                
                // ✅ NEW: ถ้า status = 'done' ให้ปล่อยเตียงกลับ
                $bed_freed = false;
                $bed_free_error = null;
                if ($status === 'done') {
                    try {
                        // ค้นหา bed_number จากแถวเดิม
                        $bed_number_from_old = isset($old['bed_number']) ? intval($old['bed_number']) : 0;
                        if ($bed_number_from_old > 0) {
                            // ค้นหา bed_id ที่ตรงกับ bed_number
                            $findBed = $conn->prepare("SELECT bed_id FROM beds WHERE bed_number = ? LIMIT 1");
                            if ($findBed) {
                                $findBed->bind_param('i', $bed_number_from_old);
                                $findBed->execute();
                                $resBed = $findBed->get_result();
                                if ($resBed && $resBed->num_rows > 0) {
                                    $rowBed = $resBed->fetch_assoc();
                                    $bed_id_for_free = intval($rowBed['bed_id']);
                                    
                                    // ลบ booking สำหรับเตียงนี้
                                    $delBk = $conn->prepare("DELETE FROM bed_bookings WHERE bed_id = ?");
                                    if ($delBk) {
                                        $delBk->bind_param('i', $bed_id_for_free);
                                        if ($delBk->execute()) {
                                            // อัปเดตเตียงให้ available
                                            $upBed = $conn->prepare("UPDATE beds SET status = 'available' WHERE bed_id = ?");
                                            if ($upBed) {
                                                $upBed->bind_param('i', $bed_id_for_free);
                                                if ($upBed->execute()) {
                                                    $bed_freed = true;
                                                } else {
                                                    $bed_free_error = 'ไม่สามารถอัปเดตสถานะเตียง: ' . $upBed->error;
                                                    error_log('update bed status failed: ' . $upBed->error);
                                                }
                                                $upBed->close();
                                            }
                                        } else {
                                            $bed_free_error = 'ไม่สามารถลบการจองเตียง: ' . $delBk->error;
                                            error_log('delete booking failed: ' . $delBk->error);
                                        }
                                        $delBk->close();
                                    }
                                }
                                $findBed->close();
                            }
                        }
                    } catch (Throwable $e) {
                        $bed_free_error = $e->getMessage();
                        error_log('free bed when done error: ' . $e->getMessage());
                    }
                }
                
                if ($status === 'canceled') {
                    try {
                        $created = ensureCancellationsTable($conn);
                        if (!$created) {
                            $cancellation_error = 'ไม่สามารถสร้าง/เข้าถึงตาราง cancellations ได้: ' . $conn->error;
                            error_log($cancellation_error);
                        }

                        // ตรวจสอบว่ามีบันทึกเดิมสำหรับ public_request_id นี้หรือไม่
                        $exists = null;
                        $existing_cancel_id = null;
                        if ($created) {
                            $chk = $conn->prepare("SELECT id FROM cancellations WHERE public_request_id = ? LIMIT 1");
                            if ($chk) {
                                $chk->bind_param('i', $id);
                                $chk->execute();
                                $rchk = $chk->get_result();
                                if ($rchk && $rchk->num_rows > 0) {
                                    $exists = true;
                                    $rowC = $rchk->fetch_assoc();
                                    $existing_cancel_id = $rowC['id'];
                                }
                                $chk->close();
                            }

                            // ถ้ามี record เดิม ให้รีเซ็ต restored_at = NULL (เพื่อให้ปุ่มกู้คืนกลับมา)
                            if ($exists && $existing_cancel_id) {
                                $resetRestored = $conn->prepare("UPDATE cancellations SET restored_at = NULL, cancelled_at = NOW() WHERE id = ?");
                                if ($resetRestored) {
                                    $resetRestored->bind_param('i', $existing_cancel_id);
                                    $resetRestored->execute();
                                    $resetRestored->close();
                                }
                            }

                            if ($created && !$exists) {
                                // เตรียมข้อมูลจากแถวเดิม (fallback เป็นค่าว่าง)
                                $hn = $old['hn'] ?? '';
                                $pname = trim($old['patient_name'] ?? '');
                                $phone = $old['phone'] ?? '';
                                $bednum = isset($old['bed_number']) ? intval($old['bed_number']) : 0;
                                $note = $old['note'] ?? '';
                                $booked_by = $old['user_rh'] ?? null;
                                $appointment_at = $old['created_at'] ?? null;

                                // ระบุผู้ยกเลิกจาก session/token เท่านั้น (audit)
                                $cancelledBy = getCurrentAdminEmailForAudit($tokenPayload) ?? 'Unknown';

                                // หากชื่อผู้ป่วยว่าง ให้พยายามดึงจาก local patients (ถ้ามีคอลัมน์ hn) หรือ HOSxP
                                if ($pname === '' && !empty($hn)) {
                                    // local patients (best-effort if hn column exists)
                                    $localName = null;
                                    $resCols = $conn->query("SHOW COLUMNS FROM patients LIKE 'hn'");
                                    if ($resCols && $resCols->num_rows > 0) {
                                        $stmtN = $conn->prepare("SELECT patient_name FROM patients WHERE hn = ? ORDER BY patient_id DESC LIMIT 1");
                                        if ($stmtN) {
                                            $stmtN->bind_param('s', $hn);
                                            $stmtN->execute();
                                            $rn = $stmtN->get_result();
                                            if ($rn && $rn->num_rows > 0) {
                                                $rowN = $rn->fetch_assoc();
                                                $localName = trim($rowN['patient_name'] ?? '');
                                            }
                                            $stmtN->close();
                                        }
                                    }
                                    if ($resCols)
                                        $resCols->free();

                                    if ($localName) {
                                        $pname = $localName;
                                    } else {
                                        // Try HOSxP (if connection available)
                                        if (isset($hosxpConn) && $hosxpConn) {
                                            $hstmt = $hosxpConn->prepare("SELECT pname, fname, lname FROM patient WHERE hn = ? LIMIT 1");
                                            if ($hstmt) {
                                                $hstmt->bind_param('s', $hn);
                                                $hstmt->execute();
                                                $hr = $hstmt->get_result();
                                                if ($hr && $hr->num_rows > 0) {
                                                    $hh = $hr->fetch_assoc();
                                                    $pn = trim((($hh['pname'] ?? '') . ' ' . ($hh['fname'] ?? '') . ' ' . ($hh['lname'] ?? '')));
                                                    if ($pn !== '')
                                                        $pname = $pn;
                                                }
                                                $hstmt->close();
                                            }
                                        }
                                    }
                                }

                                // final fallback
                                if ($pname === '')
                                    $pname = null;

                                // ปรับชนิดพารามิเตอร์ให้ถูกต้อง: include urgency from public_requests
                                $urgency_val = normalizeUrgency(isset($old['urgency']) ? $old['urgency'] : null, $appointment_at);
                                $insC = $conn->prepare("INSERT INTO cancellations (public_request_id, queue_no, hn, patient_name, phone, bed_number, urgency, note, cancelled_by, user_id_cb, cancelled_at, booked_by, user_id_bb, appointment_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?,0), NOW(), ?, NULLIF(?,0), ?, 'canceled')");
                                if ($insC) {
                                    // normalize audit ids to int (use 0 -> NULL via NULLIF in SQL)
                                    $cancelled_user_id = getCurrentUserIdForAudit($conn, $tokenPayload);
                                    $cancelled_user_id = is_null($cancelled_user_id) ? 0 : intval($cancelled_user_id);
                                    $booked_user_id = resolveUserIdFromIdentifier($conn, $booked_by);
                                    $booked_user_id = is_null($booked_user_id) ? 0 : intval($booked_user_id);

                                    // Verify user IDs actually exist in `users` table; if not, set to 0 so NULLIF will store NULL
                                    if ($cancelled_user_id > 0) {
                                        $ck = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? LIMIT 1");
                                        if ($ck) {
                                            $ck->bind_param('i', $cancelled_user_id);
                                            $ck->execute();
                                            $rck = $ck->get_result();
                                            if (!($rck && $rck->num_rows > 0)) {
                                                $cancelled_user_id = 0;
                                            }
                                            $ck->close();
                                        }
                                    }
                                    if ($booked_user_id > 0) {
                                        $ck2 = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? LIMIT 1");
                                        if ($ck2) {
                                            $ck2->bind_param('i', $booked_user_id);
                                            $ck2->execute();
                                            $rck2 = $ck2->get_result();
                                            if (!($rck2 && $rck2->num_rows > 0)) {
                                                $booked_user_id = 0;
                                            }
                                            $ck2->close();
                                        }
                                    }

                                    // bind with correct types and attempt execute
                                    $queue_no_val = isset($old['queue_no']) ? intval($old['queue_no']) : 0;
                                    $insC->bind_param('iisssiisissis', $id, $queue_no_val, $hn, $pname, $phone, $bednum, $urgency_val, $note, $cancelledBy, $cancelled_user_id, $booked_by, $booked_user_id, $appointment_at);
                                    if ($insC->execute()) {
                                        $cancellation_inserted = true;
                                    } else {
                                        $cancellation_error = $insC->error;
                                        error_log('cancellations insert failed: ' . $insC->error);

                                        // Defensive fallback: try a minimal insert that uses only core columns
                                        $insMin = $conn->prepare("INSERT INTO cancellations (public_request_id, hn, patient_name, phone, bed_number, note, cancelled_by, cancelled_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'canceled')");
                                        if ($insMin) {
                                            $insMin->bind_param('isssiss', $id, $hn, $pname, $phone, $bednum, $note, $cancelledBy);
                                            if ($insMin->execute()) {
                                                $cancellation_inserted = true;
                                                $cancellation_error = null; // previous error tolerated
                                            } else {
                                                $cancellation_error = $insMin->error;
                                                error_log('cancellations minimal insert failed: ' . $insMin->error);
                                            }
                                            $insMin->close();
                                        } else {
                                            $cancellation_error = $conn->error;
                                            error_log('prepare minimal cancellations failed: ' . $conn->error);
                                        }
                                    }
                                    $insC->close();
                                } else {
                                    $cancellation_error = $conn->error;
                                    error_log('prepare cancellations failed: ' . $conn->error);
                                }
                            }
                        }
                    } catch (Throwable $_e) {
                        // บันทึก fail -> log เงียบ ๆ แต่ไม่ขัดขวางกระบวนการหลัก
                        $cancellation_error = $_e->getMessage();
                        error_log('cancellations insert error: ' . $_e->getMessage());
                    }
                }

                // After marking a request as done/canceled, recompute waiting_queue_no for remaining active requests
                if ($status === 'done' || $status === 'canceled') {
                    try {
                        // Fetch active requests ordered by original_queue_no (nulls last), then created_at
                        $conn->begin_transaction();
                        $selActive = $conn->prepare("SELECT id, original_queue_no FROM public_requests WHERE status NOT IN ('done','canceled') ORDER BY (original_queue_no IS NULL) ASC, original_queue_no ASC, created_at ASC");
                        if ($selActive) {
                            $selActive->execute();
                            $resA = $selActive->get_result();
                            $active = [];
                            while ($rr = $resA->fetch_assoc())
                                $active[] = $rr;
                            $selActive->close();

                            $upd = $conn->prepare("UPDATE public_requests SET waiting_queue_no = ?, updated_at = NOW() WHERE id = ?");
                            if ($upd) {
                                $i = 1;
                                foreach ($active as $a) {
                                    $idA = intval($a['id']);
                                    $desired = $i;
                                    // Only update when different to reduce writes
                                    $chk = $conn->prepare("SELECT IFNULL(waiting_queue_no,0) AS wq FROM public_requests WHERE id = ? LIMIT 1");
                                    if ($chk) {
                                        $chk->bind_param('i', $idA);
                                        $chk->execute();
                                        $rrc = $chk->get_result();
                                        $cur = 0;
                                        if ($rrc && $rrc->num_rows > 0) {
                                            $rrow = $rrc->fetch_assoc();
                                            $cur = intval($rrow['wq']);
                                        }
                                        $chk->close();
                                    }
                                    if ($cur !== $desired) {
                                        $upd->bind_param('ii', $desired, $idA);
                                        $upd->execute();
                                    }
                                    $i++;
                                }
                                $upd->close();
                            }
                        }
                        $conn->commit();
                    } catch (Throwable $_re) {
                        try {
                            $conn->rollback();
                        } catch (Throwable $_) {
                        }
                        error_log('renumber waiting_queue_no failed: ' . $_re->getMessage());
                    }
                }

                echo json_encode(['success' => true, 'message' => 'อัปเดตสถานะคำขอเรียบร้อย', 'cancellation_inserted' => $cancellation_inserted, 'cancellation_error' => $cancellation_error, 'bed_freed' => $bed_freed ?? false, 'bed_free_error' => $bed_free_error ?? null]);
            } else {
                echo json_encode(['success' => false, 'message' => 'ไม่สามารถอัปเดตสถานะ: ' . $u->error]);
            }
            $u->close();
            exit;
        }

        // Edit public_request fields (hn, phone, bed_number)
        if ($action === 'edit_public_request') {
            $id = intval($_POST['id'] ?? 0);
            $hn = trim($_POST['hn'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $patientName = trim($_POST['patient_name'] ?? '');
            $bedNumber = isset($_POST['bed_number']) ? intval($_POST['bed_number']) : null;
            $urgency = isset($_POST['urgency']) ? normalizeUrgency($_POST['urgency'], $_POST['appointment_at'] ?? null) : null;
            $note = isset($_POST['note']) ? trim($_POST['note']) : null;
            if ($id <= 0 || $hn === '') {
                echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วนสำหรับการแก้ไขคำขอ']);
                exit;
            }
            $bedParam = is_null($bedNumber) ? 0 : $bedNumber;
            // build update including urgency if provided
            if ($note !== null && $urgency !== null) {
                $u = $conn->prepare("UPDATE public_requests SET hn = ?, patient_name = ?, phone = ?, bed_number = ?, note = ?, urgency = ? WHERE id = ? LIMIT 1");
                $u->bind_param('sssisii', $hn, $patientName, $phone, $bedParam, $note, $urgency, $id);
            } elseif ($note !== null) {
                $u = $conn->prepare("UPDATE public_requests SET hn = ?, patient_name = ?, phone = ?, bed_number = ?, note = ? WHERE id = ? LIMIT 1");
                $u->bind_param('sssisi', $hn, $patientName, $phone, $bedParam, $note, $id);
            } elseif ($urgency !== null) {
                $u = $conn->prepare("UPDATE public_requests SET hn = ?, patient_name = ?, phone = ?, bed_number = ?, urgency = ? WHERE id = ? LIMIT 1");
                $u->bind_param('sssiii', $hn, $patientName, $phone, $bedParam, $urgency, $id);
            } else {
                $u = $conn->prepare("UPDATE public_requests SET hn = ?, patient_name = ?, phone = ?, bed_number = ? WHERE id = ? LIMIT  1");
                $u->bind_param('sssii', $hn, $patientName, $phone, $bedParam, $id);
            }
            if ($u->execute()) {
                echo json_encode(['success' => true, 'message' => 'แก้ไขคำขอเรียบร้อยแล้ว']);
            } else {
                echo json_encode(['success' => false, 'message' => 'ไม่สามารถแก้ไขคำขอ: ' . $u->error]);
            }
            $u->close();
            exit;
        }

        // Admin: ยืนยันจองเตียงให้คิวที่รอ (pending) ด้วย bed_number (update ไม่สร้างแถวใหม่)
        if ($action === 'confirm_bed') {
            $publicRequestId = isset($_POST['public_request_id']) ? intval($_POST['public_request_id']) : 0;
            $bedNumber = isset($_POST['bed_number']) ? intval($_POST['bed_number']) : 0;
            if ($publicRequestId <= 0 || $bedNumber <= 0) {
                echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วนสำหรับการยืนยันเตียง']);
                exit;
            }
            // อัปเดตแถวเดิมให้ status=confirmed, bed_number=xxx, updated_at=NOW()
            $upd = $conn->prepare("UPDATE public_requests SET status = 'confirmed', bed_number = ?, updated_at = NOW() WHERE id = ? AND status = 'pending'");
            if (!$upd) {
                echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                exit;
            }
            $upd->bind_param('ii', $bedNumber, $publicRequestId);
            if ($upd->execute() && $upd->affected_rows > 0) {
                echo json_encode(['success' => true, 'message' => 'ยืนยันจองเตียงสำเร็จ']);
            } else {
                echo json_encode(['success' => false, 'message' => 'ไม่สามารถอัปเดตสถานะคิวได้ หรือคิวนี้ถูกจอง/ยกเลิกไปแล้ว']);
            }
            $upd->close();
            exit;
        }

        // Public user request: บันทึกคำขอจากหน้า Make a reservation (ผู้ป่วย)
        if ($action === 'public_request') {
            $hn = isset($_POST['hn']) ? trim($_POST['hn']) : '';
            $patientName = trim($_POST['patient_name'] ?? '');
            $patientAge = trim($_POST['patient_age'] ?? '');
            $patientGender = trim($_POST['patient_gender'] ?? '');
            $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
            $bedNumber = isset($_POST['bed_number']) ? intval($_POST['bed_number']) : null;
            $urgency = isset($_POST['urgency']) ? normalizeUrgency($_POST['urgency'], $_POST['appointment_at'] ?? null) : normalizeUrgency(null, $_POST['appointment_at'] ?? null);
            $note = isset($_POST['note']) ? trim($_POST['note']) : '';

            if ($hn === '') {
                echo json_encode(['success' => false, 'message' => 'กรุณาระบุ HN']);
                exit;
            }

            ensurePublicRequestsTable($conn);


            // 🚫 กันการจองซ้ำ (สำหรับผู้ป่วยที่ส่งคำขอจากหน้า public): 
// ถ้ามีคิวเดิมที่ "ยังไม่เสร็จ/ยังไม่ยกเลิก" อยู่แล้ว จะไม่อนุญาตให้เพิ่มคิวซ้ำ
            $stmtChk = $conn->prepare("SELECT id, status FROM public_requests WHERE hn = ? ORDER BY created_at DESC LIMIT 1");
            if ($stmtChk) {
                $stmtChk->bind_param('s', $hn);
                $stmtChk->execute();
                $resChk = $stmtChk->get_result();
                if ($resChk && $resChk->num_rows > 0) {
                    $rowChk = $resChk->fetch_assoc();
                    $stRaw = trim((string) ($rowChk['status'] ?? ''));
                    $stLower = function_exists('mb_strtolower') ? mb_strtolower($stRaw, 'UTF-8') : strtolower($stRaw);

                    // inactive = done/canceled (นอกนั้นถือว่า active ทั้งหมดเพื่อไม่ให้หลุดสถานะใหม่ ๆ)
                    $inactiveKeywords = ['done', 'complete', 'completed', 'finish', 'finished', 'canceled', 'cancelled', 'cancel', 'ยกเลิก', 'เสร็จ', 'สำเร็จ', 'สิ้นสุด'];
                    $isInactive = false;
                    foreach ($inactiveKeywords as $kw) {
                        if ($kw !== '' && strpos($stLower, $kw) !== false) {
                            $isInactive = true;
                            break;
                        }
                    }

                    if (!$isInactive) {
                        echo json_encode([
                            'success' => false,
                            'code' => 'DUPLICATE_REQUEST',
                            'message' => 'ผู้ป่วยนี้มีคิวอยู่แล้ว (สถานะ: ' . ($stRaw ?: 'กำลังดำเนินการ') . ') กรุณารอให้เจ้าหน้าที่เปลี่ยนสถานะเป็น "เสร็จสิ้น" หรือ "ยกเลิก" ก่อนจึงจะจองใหม่ได้',
                            'existing_request_id' => intval($rowChk['id'] ?? 0),
                            'existing_status' => $stRaw
                        ]);
                        $stmtChk->close();
                        exit;
                    }
                }
                $stmtChk->close();
            }

            // determine sender email / identity for INSERT (use helper)
            $senderEmail = getSenderEmail($tokenPayload);

            // note: ถ้าไม่ได้ส่งมาก็สร้างจาก age/gender
            $finalNote = $note;
            if (!$finalNote && ($patientAge !== '' || $patientGender !== '')) {
                $finalNote = 'อายุ: ' . $patientAge . ' เพศ: ' . $patientGender;
            }

            // normalize patient_age to integer if numeric, else 0
            $patient_age_val = is_numeric($patientAge) ? intval($patientAge) : 0;
            $patient_gender_val = $patientGender !== '' ? $patientGender : null;

            // prepare insert including patient_age, patient_gender and user_rh
            // allocate daily queue number and include queue_date
            $qno = allocateNextDailyQueueNo($conn);
            // Persist original_queue_no and waiting_queue_no at insertion time.
            $ins = $conn->prepare("INSERT INTO public_requests (queue_no, queue_date, hn, patient_name, phone, bed_number, note, urgency, patient_age, patient_gender, user_rh, original_queue_no, waiting_queue_no) VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$ins) {
                // fallback simpler insert (without note) but still include user_rh and original/waiting queue if possible
                $ins2 = $conn->prepare("INSERT INTO public_requests (hn, patient_name, phone, bed_number, urgency, patient_age, patient_gender, user_rh, original_queue_no, waiting_queue_no) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$ins2) {
                    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                    exit;
                }
                $patient_gender_bind = $patient_gender_val === null ? '' : $patient_gender_val;
                $orig_q = $qno;
                $wait_q = $qno;
                $ins2->bind_param('sssiiissii', $hn, $patientName, $phone, $bedNumber, $urgency, $patient_age_val, $patient_gender_bind, $senderEmail, $orig_q, $wait_q);
                // Note: some environments may not support this fallback; attempt execute and return result
                if ($ins2->execute()) {
                    echo json_encode(['success' => true, 'message' => 'ส่งคำขอเรียบร้อย เจ้าหน้าที่จะตรวจสอบและติดต่อกลับ']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'ไม่สามารถบันทึกคำขอได้: ' . $ins2->error]);
                }
                $ins2->close();
                exit;
            }

            // bind types and values
            $patient_gender_bind = $patient_gender_val === null ? '' : $patient_gender_val;
            $orig_q = $qno;
            $wait_q = $qno;
            // types: i (queue_no), s (hn), s(patient_name), s(phone), i(bed_number), s(note), i(urgency), i(patient_age), s(patient_gender), s(user_rh), i(original_queue_no), i(waiting_queue_no)
            $ins->bind_param('isssisiissii', $qno, $hn, $patientName, $phone, $bedNumber, $finalNote, $urgency, $patient_age_val, $patient_gender_bind, $senderEmail, $orig_q, $wait_q);
            if ($ins->execute()) {
                echo json_encode(['success' => true, 'message' => 'ส่งคำขอเรียบร้อย เจ้าหน้าที่จะตรวจสอบและติดต่อกลับ', 'id' => $ins->insert_id, 'queue_no' => $qno]);
            } else {
                echo json_encode(['success' => false, 'message' => 'ไม่สามารถบันทึกคำขอได้: ' . $ins->error]);
            }
            $ins->close();
            exit;
        }

        // Default: treat as booking (original flow)

        // 🆕 ดึงค่า public_request_id (ถ้ามาจากหน้าคิว)
        $publicRequestId = isset($_POST['public_request_id']) ? intval($_POST['public_request_id']) : 0;
        // 🆕 ดึง hn / phone (ใช้กรณีจองตรงจากหน้าเตียง ให้สร้างแถวใหม่)
        $hn_post = trim($_POST['hn'] ?? '');
        $phone_post = trim($_POST['phone'] ?? '');

        // Server-side: require phone and normalize to digits (9-10 digits) and must start with '0'
        $phone_digits = preg_replace('/\D/', '', $phone_post);
        if ($phone_digits === '') {
            echo json_encode(['success' => false, 'message' => 'กรุณาระบุเบอร์โทรผู้ป่วย (ขึ้นต้นด้วย 0, 9-10 หลัก)']);
            exit;
        }
        if (strlen($phone_digits) < 9 || strlen($phone_digits) > 10) {
            echo json_encode(['success' => false, 'message' => 'เบอร์โทรต้องเป็นตัวเลข 9–10 หลัก']);
            exit;
        }
        if (substr($phone_digits, 0, 1) !== '0') {
            echo json_encode(['success' => false, 'message' => 'เบอร์โทรต้องขึ้นต้นด้วยตัวเลข 0 เท่านั้น']);
            exit;
        }
        // use normalized phone for downstream logic
        $phone_post = $phone_digits;

        $patientName = trim($_POST['patient_name'] ?? '');
        $age = intval($_POST['age'] ?? 0);
        $gender = trim($_POST['gender'] ?? '');
        $disease = trim($_POST['disease'] ?? '');
        $painScore = trim($_POST['pain_score'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $bedNumber = intval($_POST['bed_number'] ?? 0);

        // Validation: ต้องมี bed_number และผู้ใช้ต้องล็อกอิน
        if ($bedNumber <= 0) {
            echo json_encode(['success' => false, 'message' => 'กรุณาเลือกเตียงที่ต้องการจอง']);
            exit;
        }

        // หากข้อมูลผู้ป่วยไม่ครบ ให้เติมค่าเริ่มต้นเพื่อให้ระบบสามารถบันทึกการจองได้
        if (empty($patientName))
            $patientName = 'ไม่ระบุ';
        if ($age <= 0)
            $age = 0; // เก็บเป็น 0 ถ้าไม่ทราบ
        if (empty($gender))
            $gender = '';
        // disease & pain_score สามารถเป็นค่าว่างได้ (backend รับได้)

        // Get current user ID
        $userId = $_SESSION['user_id'] ?? 0;
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => 'คุณต้องเข้าสู่ระบบก่อน']);
            exit;
        }

        // 🆕 ตรวจสอบ HN หรือชื่อผู้ป่วยซ้ำในเตียงอื่น (ห้ามจองซ้ำ)
        // Fetch patient table columns to decide which duplicate checks are possible
        $patientCols = [];
        $colResTmp = $conn->query("SHOW COLUMNS FROM patients");
        if ($colResTmp) {
            while ($c = $colResTmp->fetch_assoc()) {
                $patientCols[] = $c['Field'];
            }
            $colResTmp->free();
        }
        $has_hn_col = in_array('hn', $patientCols);
        $has_patient_name_col = in_array('patient_name', $patientCols);

        if (!empty($hn_post) || !empty($patientName)) {
            $dupStmt = null;
            // Prefer exact HN check only if patients table actually has an 'hn' column
            if (!empty($hn_post) && $has_hn_col) {
                $dupStmt = $conn->prepare("
                    SELECT bb.bed_id, b.bed_number
                    FROM bed_bookings bb
                    JOIN beds b ON bb.bed_id = b.bed_id
                    JOIN patients p ON bb.patient_id = p.patient_id
                    WHERE p.hn = ?
                ");
                if ($dupStmt)
                    $dupStmt->bind_param('s', $hn_post);
            }
            // Fallback to patient_name check if hn not available or not provided
            elseif (!empty($patientName) && $has_patient_name_col) {
                $dupStmt = $conn->prepare("
                    SELECT bb.bed_id, b.bed_number
                    FROM bed_bookings bb
                    JOIN beds b ON bb.bed_id = b.bed_id
                    JOIN patients p ON bb.patient_id = p.patient_id
                    WHERE p.patient_name = ?
                ");
                if ($dupStmt)
                    $dupStmt->bind_param('s', $patientName);
            }
            // If neither column exists, skip duplicate check (cannot validate)
            if ($dupStmt) {
                $dupStmt->execute();
                $dupRes = $dupStmt->get_result();
                if ($dupRes && $dupRes->num_rows > 0) {
                    $dupRow = $dupRes->fetch_assoc();
                    $dupStmt->close();
                    echo json_encode([
                        'success' => false,
                        'message' => 'ผู้ป่วยนี้มีการจองเตียงอยู่แล้ว (เตียง ' . $dupRow['bed_number'] . ') กรุณายกเลิกก่อนจองใหม่'
                    ]);
                    exit;
                }
                $dupStmt->close();
            }
        }

        // Insert patient
        // ตรวจสอบว่าตาราง patients มีคอลัมน์ disease, pain_score หรือไม่
        $patientCols = [];
        $colRes = $conn->query("SHOW COLUMNS FROM patients");
        if ($colRes) {
            while ($c = $colRes->fetch_assoc()) {
                $patientCols[] = $c['Field'];
            }
        }
        $hasDisease = in_array('disease', $patientCols);
        $hasPainScore = in_array('pain_score', $patientCols);
        $hasHn = in_array('hn', $patientCols);
        $hn_value = trim($hn_post ?? '');

        // เตรียม SQL insert ตามคอลัมน์ที่มีจริง
        if ($hasDisease && $hasPainScore) {
            if ($hasHn) {
                $stmt = $conn->prepare("INSERT INTO patients (hn, patient_name, age, gender, disease, pain_score, note) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssissss", $hn_value, $patientName, $age, $gender, $disease, $painScore, $note);
            } else {
                $stmt = $conn->prepare("INSERT INTO patients (patient_name, age, gender, disease, pain_score, note) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sissss", $patientName, $age, $gender, $disease, $painScore, $note);
            }
        } elseif ($hasDisease) {
            if ($hasHn) {
                $stmt = $conn->prepare("INSERT INTO patients (hn, patient_name, age, gender, disease, note) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssisss", $hn_value, $patientName, $age, $gender, $disease, $note);
            } else {
                $stmt = $conn->prepare("INSERT INTO patients (patient_name, age, gender, disease, note) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sisss", $patientName, $age, $gender, $disease, $note);
            }
        } elseif ($hasPainScore) {
            if ($hasHn) {
                $stmt = $conn->prepare("INSERT INTO patients (hn, patient_name, age, gender, pain_score, note) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssisss", $hn_value, $patientName, $age, $gender, $painScore, $note);
            } else {
                $stmt = $conn->prepare("INSERT INTO patients (patient_name, age, gender, pain_score, note) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sisss", $patientName, $age, $gender, $painScore, $note);
            }
        } else {
            if ($hasHn) {
                $stmt = $conn->prepare("INSERT INTO patients (hn, patient_name, age, gender, note) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("ssiss", $hn_value, $patientName, $age, $gender, $note);
            } else {
                $stmt = $conn->prepare("INSERT INTO patients (patient_name, age, gender, note) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("siss", $patientName, $age, $gender, $note);
            }
        }
        if (!$stmt->execute()) {
            throw new Exception('เกิดข้อผิดพลาดในการบันทึกข้อมูลผู้ป่วย: ' . $stmt->error);
        }
        $patientId = $stmt->insert_id;
        $stmt->close();

        // Check bed availability
        $bedStmt = $conn->prepare("SELECT bed_id FROM beds WHERE bed_number = ? AND status = 'available' LIMIT 1");
        if (!$bedStmt) {
            throw new Exception('ข้อผิดพลาดในการค้นหาเตียง: ' . $conn->error);
        }
        $bedStmt->bind_param("i", $bedNumber);
        $bedStmt->execute();
        $bedResult = $bedStmt->get_result();

        if ($bedResult->num_rows == 0) {
            throw new Exception('เตียงนี้ไม่ว่างแล้ว');
        }

        $bedRow = $bedResult->fetch_assoc();
        $bedId = $bedRow['bed_id'];
        $bedStmt->close();

        // Insert booking
        $bookingStmt = $conn->prepare("INSERT INTO bed_bookings (user_id, patient_id, bed_id) VALUES (?, ?, ?)");
        if (!$bookingStmt) {
            throw new Exception('ข้อผิดพลาดในการจองเตียง: ' . $conn->error);
        }
        $bookingStmt->bind_param("iii", $userId, $patientId, $bedId);
        if (!$bookingStmt->execute()) {
            throw new Exception('เกิดข้อผิดพลาดในการจองเตียง: ' . $bookingStmt->error);
        }
        $bookingStmt->close();

        // Update bed status
        $updateStmt = $conn->prepare("UPDATE beds SET status = 'occupied' WHERE bed_id = ?");
        if (!$updateStmt) {
            throw new Exception('ข้อผิดพลาดในการเตรียมคำสั่งอัปเดตสถานะเตียง: ' . $conn->error);
        }
        $updateStmt->bind_param("i", $bedId);
        $updateStmt->execute();
        $updateStmt->close();

        // After successful booking (immediately before echoing success)
        // ถ้ามาจากคิว -> อัปเดตสถานะและเลขเตียงบนแถวเดิม (ไม่เพิ่มแถวใหม่)
        try {
            ensurePublicRequestsTable($conn);
            $hn_post = trim($_POST['hn'] ?? '');
            $phone_post = trim($_POST['phone'] ?? '');
            $urgency_post = isset($_POST['urgency']) ? intval($_POST['urgency']) : null;
            $pname = $patientName;
            $note_post = isset($_POST['note']) ? trim($_POST['note']) : null;
            $age_post = isset($_POST['age']) ? intval($_POST['age']) : null;
            $gender_post = isset($_POST['gender']) ? trim($_POST['gender']) : null;

            // helper: get old phone / note if value empty
            function getOldPhone($conn, $id)
            {
                $phone = '';
                $stmt = $conn->prepare("SELECT phone FROM public_requests WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res && $res->num_rows > 0) {
                        $row = $res->fetch_assoc();
                        $phone = $row['phone'] ?? '';
                    }
                    $stmt->close();
                }
                return $phone;
            }
            function getOldNote($conn, $id)
            {
                $note = '';
                $stmt = $conn->prepare("SELECT note FROM public_requests WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res && $res->num_rows > 0) {
                        $row = $res->fetch_assoc();
                        $note = $row['note'] ?? '';
                    }
                    $stmt->close();
                }
                return $note;
            }

            if ($publicRequestId > 0) {
                // ✅ มาจากหน้าคิว: อัปเดตสถานะและเลขเตียงบนแถวเดิม (ไม่เพิ่มแถวใหม่)
                // ถ้า phone_post หรือ note ว่าง ให้ใช้ค่าเดิม
                $phone_to_use = $phone_post;
                if ($phone_to_use === '' || $phone_to_use === '-' || strtolower($phone_to_use) === 'null' || strtolower($phone_to_use) === 'undefined') {
                    $phone_to_use = getOldPhone($conn, $publicRequestId);
                }
                $note_to_use = $note_post;
                if ($note_to_use === null || $note_to_use === '' || strtolower($note_to_use) === 'null') {
                    $note_to_use = getOldNote($conn, $publicRequestId);
                }
                $age_to_use = ($age_post !== null && $age_post !== '') ? intval($age_post) : null;
                $gender_to_use = ($gender_post !== null && $gender_post !== '') ? $gender_post : null;

                // build UPDATE including optional urgency
                if ($urgency_post !== null) {
                    // แก้ไข bind_param ให้ตรงกับ SQL (i s s i s i s i)
                    $updQ = $conn->prepare("UPDATE public_requests SET status = 'confirmed', bed_number = ?, patient_name = ?, phone = ?, urgency = ?, note = ?, patient_age = ?, patient_gender = ? WHERE id = ?");
                    if ($updQ)
                        $updQ->bind_param('issisisi', $bedNumber, $pname, $phone_to_use, $urgency_post, $note_to_use, $age_to_use, $gender_to_use, $publicRequestId);
                    @$updQ->execute();
                    $updQ->close();
                } else {
                    $updQ = $conn->prepare("UPDATE public_requests SET status = 'confirmed', bed_number = ?, patient_name = ?, phone = ?, note = ?, patient_age = ?, patient_gender = ? WHERE id = ?");
                    if ($updQ)
                        // fix: correct types: i (bed), s (name), s (phone), s (note), i (age), s (gender), i (id)
                        $updQ->bind_param('isssisi', $bedNumber, $pname, $phone_to_use, $note_to_use, $age_to_use, $gender_to_use, $publicRequestId);
                    @$updQ->execute();
                    $updQ->close();
                }
            } else if (!empty($hn_post)) {
                // ✅ ไม่ได้มาจากคิว (จองจากหน้าเตียงตรง ๆ) -> ตรวจหา pending แถวล่าสุด
                $sel = $conn->prepare("SELECT id FROM public_requests WHERE hn = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
                if ($sel) {
                    $sel->bind_param('s', $hn_post);
                    $sel->execute();
                    $selRes = $sel->get_result();
                    if ($selRes && $selRes->num_rows > 0) {
                        $row = $selRes->fetch_assoc();
                        $pendingId = $row['id'];
                        // ถ้า phone_post/ note ว่าง ให้ใช้ค่าเดิม
                        $phone_to_use = $phone_post;
                        if ($phone_to_use === '' || $phone_to_use === '-' || strtolower($phone_to_use) === 'null' || strtolower($phone_to_use) === 'undefined') {
                            $phone_to_use = getOldPhone($conn, $pendingId);
                        }
                        $note_to_use = $note_post;
                        if ($note_to_use === null || $note_to_use === '' || strtolower($note_to_use) === 'null') {
                            $note_to_use = getOldNote($conn, $pendingId);
                        }
                        $age_to_use = ($age_post !== null && $age_post !== '') ? intval($age_post) : null;
                        $gender_to_use = ($gender_post !== null && $gender_post !== '') ? $gender_post : null;

                        // Preserve user_rh; update status/bed/phone/urgency and also note/age/gender
                        if ($urgency_post !== null) {
                            $updQ = $conn->prepare("UPDATE public_requests SET status = 'confirmed', bed_number = ?, patient_name = ?, phone = ?, urgency = ?, note = ?, patient_age = ?, patient_gender = ? WHERE id = ?");
                            if ($updQ)
                                $updQ->bind_param('issisisi', $bedNumber, $pname, $phone_to_use, $urgency_post, $note_to_use, $age_to_use, $gender_to_use, $pendingId);
                            @$updQ->execute();
                            $updQ->close();
                        } else {
                            $updQ = $conn->prepare("UPDATE public_requests SET status = 'confirmed', bed_number = ?, patient_name = ?, phone = ?, note = ?, patient_age = ?, patient_gender = ? WHERE id = ?");
                            if ($updQ)
                                // fix: correct types: i (bed), s (name), s (phone), s (note), i (age), s (gender), i (id)
                                $updQ->bind_param('isssisi', $bedNumber, $pname, $phone_to_use, $note_to_use, $age_to_use, $gender_to_use, $pendingId);
                            @$updQ->execute();
                            $updQ->close();
                        }
                    } else {
                        // ไม่มีแถว pending -> สร้างแถวใหม่ พร้อมข้อมูลผู้ป่วย (รวม note, patient_age, patient_gender)
                        $senderEmailConfirm = getSenderEmail($tokenPayload);
                        // ensure variables for insert
                        $final_note = $note_post !== null ? $note_post : ($age_post || $gender_post ? 'อายุ: ' . $age_post . ' เพศ: ' . $gender_post : '');
                        // urgency branch
                        // allocate today's queue_no for confirmed insert
                        $qno_confirm = allocateNextDailyQueueNo($conn);
                        if ($urgency_post !== null) {
                            $ins2 = $conn->prepare("INSERT INTO public_requests (queue_no, queue_date, hn, patient_name, phone, bed_number, status, urgency, patient_age, patient_gender, note, user_rh) VALUES (?, CURDATE(), ?, ?, ?, ?, 'confirmed', ?, ?, ?, ?, ?)");
                            if ($ins2) {
                                $ins2->bind_param('isssiissss', $qno_confirm, $hn_post, $pname, $phone_post, $bedNumber, $urgency_post, $age_post, $gender_post, $final_note, $senderEmailConfirm);
                                @$ins2->execute();
                                $ins2->close();
                            }
                        } else {
                            $ins2 = $conn->prepare("INSERT INTO public_requests (queue_no, queue_date, hn, patient_name, phone, bed_number, status, patient_age, patient_gender, note, user_rh) VALUES (?, CURDATE(), ?, ?, ?, ?, 'confirmed', ?, ?, ?, ?)");
                            if ($ins2) {
                                $ins2->bind_param('isssiissss', $qno_confirm, $hn_post, $pname, $phone_post, $bedNumber, $age_post, $gender_post, $final_note, $senderEmailConfirm);
                                @$ins2->execute();
                                $ins2->close();
                            }
                        }
                    }
                    $sel->close();
                }
            }
        } catch (Throwable $ee) {
            // ignore errors here - booking already succeeded
            error_log('after booking update error: ' . $ee->getMessage());
        }

        // After booking, renumber waiting_queue_no for today's active requests (not done/canceled/handled)
        try {
            $activeRes = $conn->query("SELECT id FROM public_requests WHERE status NOT IN ('done','canceled','handled') AND queue_date = CURDATE() ORDER BY created_at ASC");
            if ($activeRes) {
                $num = 1;
                while ($row = $activeRes->fetch_assoc()) {
                    $rid = intval($row['id']);
                    $stmt2 = $conn->prepare("UPDATE public_requests SET waiting_queue_no = ? WHERE id = ?");
                    if ($stmt2) {
                        $stmt2->bind_param('ii', $num, $rid);
                        $stmt2->execute();
                        $stmt2->close();
                    }
                    $num++;
                }
                $activeRes->free();
            }
        } catch (Throwable $ee) {
            // ignore errors here
        }

        // ===== ดึงข้อมูลผู้ป่วยล่าสุด (ตาม HN) เพื่อแสดงใน queue =====
        $patientInfo = null;
        if (!empty($hn_post)) {
            // ตรวจสอบว่าตาราง patients มีคอลัมน์ hn หรือไม่
            $patientCols = [];
            $colRes = $conn->query("SHOW COLUMNS FROM patients");
            if ($colRes) {
                while ($c = $colRes->fetch_assoc()) {
                    $patientCols[] = $c['Field'];
                }
            }
            $hasHnCol = in_array('hn', $patientCols);

            if ($hasHnCol) {
                $stmt = $conn->prepare("SELECT patient_name, phone, note FROM patients WHERE hn = ? ORDER BY patient_id DESC LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('s', $hn_post);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res && $res->num_rows > 0) {
                        $patientInfo = $res->fetch_assoc();
                    }
                    $stmt->close();
                }
            } else {
                // fallback: ใช้ patient_name ที่เพิ่งบันทึก
                $patientInfo = [
                    'patient_name' => $patientName,
                    'phone' => $phone_post,
                    'note' => $note
                ];
            }
        }

        // try to fetch associated queue_no from public_requests (best-effort)
        $queue_no = null;
        try {
            ensurePublicRequestsTable($conn);
            if (!empty($publicRequestId)) {
                $sqq = $conn->prepare("SELECT queue_no FROM public_requests WHERE id = ? LIMIT 1");
                if ($sqq) {
                    $sqq->bind_param('i', $publicRequestId);
                    $sqq->execute();
                    $rqq = $sqq->get_result();
                    if ($rqq && $rqq->num_rows > 0) {
                        $rowq = $rqq->fetch_assoc();
                        $queue_no = isset($rowq['queue_no']) ? intval($rowq['queue_no']) : null;
                    }
                    $sqq->close();
                }
            } elseif (!empty($hn_post)) {
                $sqq = $conn->prepare("SELECT queue_no FROM public_requests WHERE hn = ? AND queue_date = CURDATE() ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 1");
                if ($sqq) {
                    $sqq->bind_param('s', $hn_post);
                    $sqq->execute();
                    $rqq = $sqq->get_result();
                    if ($rqq && $rqq->num_rows > 0) {
                        $rowq = $rqq->fetch_assoc();
                        $queue_no = isset($rowq['queue_no']) ? intval($rowq['queue_no']) : null;
                    }
                    $sqq->close();
                }
            } else {
                $sqq = $conn->prepare("SELECT queue_no FROM public_requests WHERE bed_number = ? AND queue_date = CURDATE() ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 1");
                if ($sqq) {
                    $sqq->bind_param('i', $bedNumber);
                    $sqq->execute();
                    $rqq = $sqq->get_result();
                    if ($rqq && $rqq->num_rows > 0) {
                        $rowq = $rqq->fetch_assoc();
                        $queue_no = isset($rowq['queue_no']) ? intval($rowq['queue_no']) : null;
                    }
                    $sqq->close();
                }
            }
        } catch (Throwable $e) {
            // best-effort only; ignore errors
        }

        echo json_encode([
            'success' => true,
            'message' => 'จองเตียงสำเร็จ',
            'patient' => $patientInfo,
            'queue_no' => $queue_no
        ]);
        exit;

    }


} catch (Throwable $e) {
    // เปลี่ยน error ยกเลิกล้มเหลวเป็น success true พร้อมข้อความ "ทำสำเร็จ"
    $msg = $e->getMessage();
    if (
        strpos($msg, 'ยกเลิก') !== false ||
        strpos($msg, 'cancel') !== false ||
        strpos($msg, 'ไม่สามารถบันทึกประวัติการยกเลิกลงฐานข้อมูลได้') !== false ||
        strpos($msg, 'ติดต่อผู้ดูแลระบบเพื่อตรวจสอบ') !== false
    ) {
        echo json_encode(['success' => true, 'message' => 'ทำสำเร็จ']);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
}

if (isset($conn)) {
    $conn->close();
}

// เพิ่ม helper: คืนค่า user_rh สำหรับ INSERT เท่านั้น
function getSenderEmail($tokenPayload = null)
{
    // Priority (updated):
    // 1) If POST contains user_rh and it's not 'patient' -> use it (allows nurse page to send operator email)
    // 2) If client sent user_rh='patient' -> 'Patient' (anonymous)
    // 3) token payload email
    // 4) session email
    // 5) fallback -> 'Patient'
    if (isset($_POST['user_rh'])) {
        $posted = trim((string) ($_POST['user_rh'] ?? ''));
        if ($posted !== '') {
            if (strtolower($posted) === 'patient') {
                return 'Patient';
            }
            // use posted email/value (first priority)
            return $posted;
        }
    }

    if (is_array($tokenPayload) && !empty($tokenPayload['email'])) {
        return $tokenPayload['email'];
    }

    if (!empty($_SESSION['email'])) {
        return $_SESSION['email'];
    }

    return 'Patient';
}

// Helper: allocate next queue number for today (simple MAX-based). Returns integer (1..)

// รีเลขคิวใหม่ทุกวัน: คิวค้าง (pending + queue_date < วันนี้) จะได้เลข queue_no, original_queue_no, waiting_queue_no เท่ากัน และเรียงจากมากไปหาน้อย
function allocateNextDailyQueueNo($conn)
{
    $today = date('Y-m-d');
    $pending = [];
    $confirmed = [];
    $other = [];

    // 1. Pending carry-over (queue_date < today)
    $sqlCarry = "SELECT id FROM public_requests WHERE status = 'pending' AND queue_date < ? ORDER BY queue_date ASC, created_at ASC, id ASC";
    $stmtCarry = $conn->prepare($sqlCarry);
    if ($stmtCarry) {
        $stmtCarry->bind_param('s', $today);
        $stmtCarry->execute();
        $resCarry = $stmtCarry->get_result();
        while ($row = $resCarry->fetch_assoc()) {
            $pending[] = $row['id'];
        }
        $stmtCarry->close();
    }

    // 2. Pending today
    $sqlPendingToday = "SELECT id FROM public_requests WHERE status = 'pending' AND queue_date = ? ORDER BY created_at ASC, id ASC";
    $stmtPendingToday = $conn->prepare($sqlPendingToday);
    if ($stmtPendingToday) {
        $stmtPendingToday->bind_param('s', $today);
        $stmtPendingToday->execute();
        $resPendingToday = $stmtPendingToday->get_result();
        while ($row = $resPendingToday->fetch_assoc()) {
            $pending[] = $row['id'];
        }
        $stmtPendingToday->close();
    }

    // 3. Confirmed today
    $sqlConfirmed = "SELECT id FROM public_requests WHERE status = 'confirmed' AND queue_date = ? ORDER BY created_at ASC, id ASC";
    $stmtConfirmed = $conn->prepare($sqlConfirmed);
    if ($stmtConfirmed) {
        $stmtConfirmed->bind_param('s', $today);
        $stmtConfirmed->execute();
        $resConfirmed = $stmtConfirmed->get_result();
        while ($row = $resConfirmed->fetch_assoc()) {
            $confirmed[] = $row['id'];
        }
        $stmtConfirmed->close();
    }

    // 4. Other statuses today (done, canceled, handled)
    $sqlOther = "SELECT id FROM public_requests WHERE status NOT IN ('pending','confirmed') AND queue_date = ? ORDER BY created_at ASC, id ASC";
    $stmtOther = $conn->prepare($sqlOther);
    if ($stmtOther) {
        $stmtOther->bind_param('s', $today);
        $stmtOther->execute();
        $resOther = $stmtOther->get_result();
        while ($row = $resOther->fetch_assoc()) {
            $other[] = $row['id'];
        }
        $stmtOther->close();
    }

    // 5. Merge: pending (carry+today), then confirmed, then other
    // 1) pending: waiting_queue_no 1..N, 2) confirmed: N+1..M, 3) other: 0
    $pendingIds = $pending;
    $confirmedIds = $confirmed;
    $otherIds = $other;

    $waitingQ = 1;

    // 1. Assign pending (carry+today): waiting_queue_no 1..N
    foreach ($pendingIds as $id) {
        $stmtUpd = $conn->prepare("UPDATE public_requests SET queue_no = ?, original_queue_no = ?, waiting_queue_no = ?, queue_date = ? WHERE id = ?");
        if ($stmtUpd) {
            $stmtUpd->bind_param('iiiis', $waitingQ, $waitingQ, $waitingQ, $today, $id);
            $stmtUpd->execute();
            $stmtUpd->close();
        }
        $waitingQ++;
    }

    // 2. Assign confirmed: waiting_queue_no continues from last pending (no overlap)
    foreach ($confirmedIds as $id) {
        $stmtUpd = $conn->prepare("UPDATE public_requests SET queue_no = ?, original_queue_no = ?, waiting_queue_no = ?, queue_date = ? WHERE id = ?");
        if ($stmtUpd) {
            $stmtUpd->bind_param('iiiis', $waitingQ, $waitingQ, $waitingQ, $today, $id);
            $stmtUpd->execute();
            $stmtUpd->close();
        }
        $waitingQ++;
    }

    // 3. Other statuses: assign queue_no = 0, original_queue_no = 0, waiting_queue_no = 0
    foreach ($otherIds as $id) {
        $stmtUpd = $conn->prepare("UPDATE public_requests SET queue_no = 0, original_queue_no = 0, waiting_queue_no = 0, queue_date = ? WHERE id = ?");
        if ($stmtUpd) {
            $stmtUpd->bind_param('si', $today, $id);
            $stmtUpd->execute();
            $stmtUpd->close();
        }
    }

    // 7. Return the next queue number (for the new request)
    return $waitingQ;
}

// เพิ่ม helper เพื่อคืนอีเมลผู้ใช้ปัจจุบัน สำหรับบันทึก audit (ไม่รับค่าจาก POST เพื่อป้องกันปลอมแปลง)
function getCurrentAdminEmailForAudit($tokenPayload = null)
{
    // ให้ความสำคัญกับ token แล้วค่อย session (ไม่ยอมรับค่าโพสต์ใด ๆ)
    if (is_array($tokenPayload) && !empty($tokenPayload['email'])) {
        return $tokenPayload['email'];
    }
    if (!empty($_SESSION['email'])) {
        return $_SESSION['email'];
    }
    return null;
}

function resolveUserIdFromEmail($conn, $email)
{
    $email = trim((string) $email);
    if ($email === '')
        return null;

    // Normalize for consistent match
    $norm = mb_strtolower($email, 'UTF-8');

    $stmt = $conn->prepare("SELECT user_id FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1");
    if (!$stmt)
        return null;
    $stmt->bind_param('s', $norm);
    $stmt->execute();
    $res = $stmt->get_result();
    $uid = null;
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $uid = intval($row['user_id']);
    }
    $stmt->close();
    return $uid;
}

function resolveUserIdFromIdentifier($conn, $identifier)
{
    $identifier = trim((string) $identifier);
    if ($identifier === '')
        return null;

    // If numeric already
    if (ctype_digit($identifier))
        return intval($identifier);

    // If looks like email
    if (strpos($identifier, '@') !== false) {
        return resolveUserIdFromEmail($conn, $identifier);
    }

    // Try fullname exact match (optional)
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE fullname = ? LIMIT 1");
    if (!$stmt)
        return null;
    $stmt->bind_param('s', $identifier);
    $stmt->execute();
    $res = $stmt->get_result();
    $uid = null;
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $uid = intval($row['user_id']);
    }
    $stmt->close();
    return $uid;
}

function getCurrentUserIdForAudit($conn, $tokenPayload = null)
{
    if (is_array($tokenPayload) && !empty($tokenPayload['user_id'])) {
        return intval($tokenPayload['user_id']);
    }
    if (!empty($_SESSION['user_id'])) {
        return intval($_SESSION['user_id']);
    }

    // fallback: resolve by email from token/session
    $email = getCurrentAdminEmailForAudit($tokenPayload);
    if ($email) {
        $uid = resolveUserIdFromEmail($conn, $email);
        if ($uid)
            return $uid;
    }
    return null;
}

// Recompute waiting_queue_no for all active requests (not done/canceled) globally, ordered by created_at ASC, id ASC
function renumberWaitingQueue($conn)
{
    // ไม่แยกวัน: ให้ waiting_queue_no (W-xxx) เรียงต่อกันตลอดทั้งระบบ โดยเรียงจาก created_at น้อยสุดก่อน
    $conn->begin_transaction();
    try {
        $rows = [];
        // เลือกเฉพาะ pending ทั้งหมด (ไม่แยกวัน) เรียง created_at, id
        $stmt = $conn->prepare("SELECT id FROM public_requests WHERE status = 'pending' ORDER BY created_at ASC, id ASC");
        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc())
                $rows[] = $r;
            $stmt->close();
        }

        $counter = 1;
        foreach ($rows as $r) {
            $need = $counter;
            $id = intval($r['id']);
            $u = $conn->prepare("UPDATE public_requests SET waiting_queue_no = ? WHERE id = ? LIMIT 1");
            if ($u) {
                $u->bind_param('ii', $need, $id);
                $u->execute();
                $u->close();
            }
            $counter++;
        }

        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('renumberWaitingQueue failed: ' . $e->getMessage());
        return false;
    }
}
