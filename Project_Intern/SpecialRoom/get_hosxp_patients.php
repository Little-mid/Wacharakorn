<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'db_hosxp.php';

if (!$hosxpConn) {
    echo json_encode(['success' => false, 'message' => 'ไม่สามารถเชื่อมต่อฐาน HOSxP']);
    exit;
}

$hn = isset($_GET['hn']) ? preg_replace('/\D/', '', $_GET['hn']) : '';
$query = isset($_GET['query']) ? preg_replace('/\D/', '', $_GET['query']) : '';

try {
    // ตรวจสอบคอลัมน์ที่มีในตาราง patient
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
    while ($c = $colRes->fetch_assoc()) $cols[] = $c['COLUMN_NAME'];
    $colStmt->close();

    $birthCol = in_array('birthday', $cols) ? 'birthday' : (in_array('birth', $cols) ? 'birth' : null);
    $has_age_y = in_array('age_y', $cols);

    // กำหนดฟิลด์ที่จะ select
    $selectParts = [];
    if (in_array('hn', $cols)) $selectParts[] = 'hn';
    if (in_array('pname', $cols)) $selectParts[] = 'pname';
    if (in_array('fname', $cols)) $selectParts[] = 'fname';
    if (in_array('lname', $cols)) $selectParts[] = 'lname';
    if (in_array('sex', $cols)) $selectParts[] = 'sex';
    if (in_array('pdx', $cols)) $selectParts[] = 'pdx';
    if ($has_age_y) $selectParts[] = 'age_y';
    if ($birthCol) $selectParts[] = $birthCol . ' AS birth_raw';

    // add telephone fields if exist
    if (in_array('hometel', $cols)) $selectParts[] = 'hometel';
    if (in_array('informtel', $cols)) $selectParts[] = 'informtel';

    if (empty($selectParts)) {
        echo json_encode(['success' => false, 'message' => 'ไม่พบคอลัมน์ที่ต้องการในตาราง patient']);
        exit;
    }
    $selectSql = implode(', ', $selectParts);

    // หากส่ง hn (exact) ให้ค้นหา exact ก่อน
    if ($hn !== '') {
        $sql = "SELECT $selectSql FROM patient WHERE hn = ? LIMIT 1";
        $stmt = $hosxpConn->prepare($sql);
        $stmt->bind_param('s', $hn);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc() ?: null;
        $stmt->close();

        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบ HN นี้']);
            exit;
        }

        // คำนวณ age
        $age = null;
        if ($birthCol && !empty($row['birth_raw'])) {
            $b = $row['birth_raw'];
            $dob = null;
            if (preg_match('/^\d{8}$/', $b)) $dob = DateTime::createFromFormat('Ymd', $b);
            else $dob = date_create($b);
            if ($dob instanceof DateTime) $age = (int)(new DateTime())->diff($dob)->y;
            $row['birth'] = $b;
        }
        if ($age === null && $has_age_y && isset($row['age_y'])) $age = (int)$row['age_y'];
        if ($age !== null) $row['age'] = $age;

        echo json_encode(['success' => true, 'patient' => $row]);
        exit;
    }

    // ถ้ามี query (partial) ให้ค้นหาแบบ LIKE บน hn (และ return หลายรายการ)
    if ($query !== '') {
        $like = '%' . $query . '%';
        $sql = "SELECT $selectSql FROM patient WHERE hn LIKE ? ORDER BY hn ASC LIMIT 200";
        $stmt = $hosxpConn->prepare($sql);
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $res = $stmt->get_result();

        $patients = [];
        while ($row = $res->fetch_assoc()) {
            $age = null;
            if ($birthCol && !empty($row['birth_raw'])) {
                $b = $row['birth_raw'];
                $dob = null;
                if (preg_match('/^\d{8}$/', $b)) $dob = DateTime::createFromFormat('Ymd', $b);
                else $dob = date_create($b);
                if ($dob instanceof DateTime) $age = (int)(new DateTime())->diff($dob)->y;
                $row['birth'] = $b;
            }
            if ($age === null && $has_age_y && isset($row['age_y'])) $age = (int)$row['age_y'];
            if ($age !== null) $row['age'] = $age;
            $patients[] = $row;
        }
        $stmt->close();

        echo json_encode(['success' => true, 'patients' => $patients]);
        exit;
    }

    // หากไม่มีพาราม์เตอร์ คืนรายการจำกัดจำนวน (เพื่อเติม datalist)
    $limit = 200;
    $sql = "SELECT $selectSql FROM patient ORDER BY hn ASC LIMIT ?";
    $stmt = $hosxpConn->prepare($sql);
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $res = $stmt->get_result();

    $patients = [];
    while ($row = $res->fetch_assoc()) {
        $age = null;
        if ($birthCol && !empty($row['birth_raw'])) {
            $b = $row['birth_raw'];
            $dob = null;
            if (preg_match('/^\d{8}$/', $b)) $dob = DateTime::createFromFormat('Ymd', $b);
            else $dob = date_create($b);
            if ($dob instanceof DateTime) $age = (int)(new DateTime())->diff($dob)->y;
            $row['birth'] = $b;
        }
        if ($age === null && $has_age_y && isset($row['age_y'])) $age = (int)$row['age_y'];
        if ($age !== null) $row['age'] = $age;
        $patients[] = $row;
    }
    $stmt->close();

    echo json_encode(['success' => true, 'patients' => $patients]);
    exit;

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
?>
