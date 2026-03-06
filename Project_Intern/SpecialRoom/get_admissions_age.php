<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'db_hosxp.php';

if (!$hosxpConn) {
    echo json_encode(['success' => false, 'message' => 'ไม่สามารถเชื่อมต่อฐาน HOSxP']);
    exit;
}

// รับพาราม์เตอร์วันที่ (YYYY-MM-DD) แบบ GET หรือ POST
$start = isset($_REQUEST['start_date']) ? trim($_REQUEST['start_date']) : '';
$end   = isset($_REQUEST['end_date']) ? trim($_REQUEST['end_date']) : '';

function bad($msg) {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

// ตรวจสอบรูปแบบวันที่พื้นฐาน
if ($start === '' || $end === '') {
    bad('กรุณาระบุ start_date และ end_date ในรูปแบบ YYYY-MM-DD');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    bad('รูปแบบวันที่ไม่ถูกต้อง ต้องเป็น YYYY-MM-DD');
}

// ป้องกันช่วงวันที่กลับหัว
if (strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
    bad('ช่วงวันที่ไม่ถูกต้อง');
}

try {
    // SQL: คำนวณอายุฝั่ง DB ด้วย TIMESTAMPDIFF
    $sql = "
        SELECT
            a.an,
            ipt.regdate,
            ipt.dchdate,
            pt.hn,
            pt.fname,
            pt.lname,
            w.`name` AS ward_name,
            pt.birthday,
            -- age คำนวณจาก birthday (ถ้า birthday เป็น NULL จะได้ NULL)
            IFNULL(TIMESTAMPDIFF(YEAR, pt.birthday, CURDATE()), NULL) AS age
        FROM
            an_stat a
            INNER JOIN patient pt ON pt.hn = a.hn
            INNER JOIN ipt ON a.an = ipt.an
            INNER JOIN ward w ON w.ward = ipt.ward
        WHERE
            ipt.regdate BETWEEN ? AND ?
        ORDER BY ipt.regdate ASC
    ";

    $stmt = $hosxpConn->prepare($sql);
    if (!$stmt) throw new Exception('Prepare failed: ' . $hosxpConn->error);

    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($r = $res->fetch_assoc()) {
        // normalize nulls and types
        if (isset($r['age'])) {
            $r['age'] = $r['age'] === null ? null : (int)$r['age'];
        } else {
            $r['age'] = null;
        }
        $rows[] = $r;
    }

    $stmt->close();

    echo json_encode(['success' => true, 'count' => count($rows), 'data' => $rows]);
    exit;

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
?>
