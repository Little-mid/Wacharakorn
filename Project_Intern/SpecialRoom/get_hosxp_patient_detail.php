<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'db_hosxp.php';

// เชื่อมต่อฐาน HOSxP
if (!$hosxpConn) {
    echo json_encode(['success' => false, 'message' => 'ไม่สามารถเชื่อมต่อฐาน HOSxP']);
    exit;
}

// รับค่า HN จากพารามิเตอร์ GET
$hn = isset($_GET['hn']) ? trim($_GET['hn']) : '';
if ($hn === '' || !preg_match('/^\d+$/', $hn)) {
    echo json_encode(['success' => false, 'message' => 'กรุณาระบุหมายเลข HN (ตัวเลขเท่านั้น)']);
    exit;
}


try {
    // ตรวจสอบคอลัมน์ในตาราง patient เพื่อหา birth หรือ age_y
    $tableSchema = HOSXP_NAME;
    $tableName = 'patient';
    $cols = [];
    $colStmt = $hosxpConn->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
    ");
    $colStmt->bind_param('ss', $tableSchema, $tableName);
    $colStmt->execute();
    $colRes = $colStmt->get_result();
    while ($c = $colRes->fetch_assoc()) {
        $cols[] = $c['COLUMN_NAME'];
    }
    $colStmt->close();

    $birthCol = null;

    // เพิ่มเช็ค 'birthday' ก่อน (ชื่อที่ HOSxP ใช้จริง)
    if (in_array('birthday', $cols)) {
        $birthCol = 'birthday';
    } elseif (in_array('birth', $cols)) {
        $birthCol = 'birth';
    } elseif (in_array('birthdate', $cols)) {
        $birthCol = 'birthdate';
    }

    $has_age_y = in_array('age_y', $cols);


    // Build SELECT including birth if available
    $selectParts = [
        'a.an',
        'ipt.regdate',
        'ipt.dchdate',
        'pt.hn',
        // optional name parts if available
    ];
    if (in_array('pname', $cols))
        $selectParts[] = 'pt.pname';
    if (in_array('fname', $cols))
        $selectParts[] = 'pt.fname';
    if (in_array('lname', $cols))
        $selectParts[] = 'pt.lname';
    if (in_array('sex', $cols))
        $selectParts[] = 'pt.sex';
    if (in_array('pdx', $cols))
        $selectParts[] = 'pt.pdx';
    if ($has_age_y)
        $selectParts[] = 'pt.age_y';
    if ($birthCol)
        $selectParts[] = "pt.{$birthCol}";

    // include phone fields if present
    if (in_array('hometel', $cols))
        $selectParts[] = 'pt.hometel';
    if (in_array('informtel', $cols))
        $selectParts[] = 'pt.informtel';

    // include ward name so frontend can display it
    $selectParts[] = 'w.name AS ward_name';

    $selectSql = implode(', ', $selectParts);

    $sql = "
        SELECT $selectSql
        FROM an_stat a
        INNER JOIN patient pt ON pt.hn = a.hn
        INNER JOIN ipt ON a.an = ipt.an
        INNER JOIN ward w ON w.ward = ipt.ward
        WHERE a.hn = ?
        ORDER BY ipt.regdate DESC
        LIMIT 1
    ";

    $stmt = $hosxpConn->prepare($sql);
    if (!$stmt)
        throw new Exception('Prepare failed: ' . $hosxpConn->error);

    $stmt->bind_param('s', $hn);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();

        // คำนวณอายุจากวันเกิด (birth/birthdate) ถ้ามี
        $age = null;
        if ($birthCol && !empty($row[$birthCol])) {
            $b = $row[$birthCol];
            $dob = null;
            if (preg_match('/^\d{8}$/', $b)) {
                $dob = DateTime::createFromFormat('Ymd', $b);
            } else {
                // รองรับ YYYY-MM-DD, DD/MM/YYYY, ฯลฯ (พยายาม parse)
                $dob = date_create($b);
            }
            if ($dob instanceof DateTime) {
                $now = new DateTime();
                $age = (int) $now->diff($dob)->y;
            }
            // เก็บวันเกิดแบบดิบด้วย
            $row['birth'] = $b;
        }

        // ถ้าไม่มีวันเกิด ให้ fallback ใช้ age_y ถ้ามี
        if ($age === null && $has_age_y && isset($row['age_y'])) {
            $age = (int) $row['age_y'];
        }

        if ($age !== null)
            $row['age'] = $age;

        // เพิ่มชื่อ ward_name (เหมือนก่อน) ถ้ามี
        // ดึง ward name: เร already joined ward as w, include it
        // If not present, it's optional - earlier query didn't select w.name; add if available
        // (but we can fill ward_name via another quick query if needed)
        // For now, try to include from row if present
        // Return JSON
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูล admission สำหรับ HN นี้']);
    }

    $stmt->close();
    exit;
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
?>