<?php
header('Content-Type: application/json; charset=utf-8');

try {
    require_once 'config.php';

    // ตรวจสอบการเชื่อมต่อ
    if (!$conn) {
        throw new Exception('ไม่สามารถเชื่อมต่อฐานข้อมูล');
    }

    // --- NEW: ตรวจสอบว่าตารางหลักมีอยู่ก่อน ---
    $required = ['beds', 'bed_bookings'];
    $missing = [];
    foreach ($required as $t) {
        $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'");
        if (!$r || $r->num_rows === 0) $missing[] = $t;
        if ($r) $r->free();
    }
    if (!empty($missing)) {
        echo json_encode([
            'success' => false,
            'message' => 'ตารางฐานข้อมูลขาดหาย: ' . implode(', ', $missing),
            'missing_tables' => $missing,
            'hint' => 'โปรดนำเข้าไฟล์ SQL โครงสร้างฐานข้อมูล หรือสร้างตารางที่ขาดก่อนใช้งาน'
        ]);
        exit;
    }

    // ดึงข้อมูลจำนวนเตียงทั้งหมด
    $totalResult = $conn->query("SELECT COUNT(*) as total FROM beds");
    if (!$totalResult) {
        throw new Exception('ข้อผิดพลาดในการดึงข้อมูลเตียง: ' . $conn->error);
    }
    $totalRow = $totalResult->fetch_assoc();
    $totalBeds = isset($totalRow['total']) ? intval($totalRow['total']) : 20;

    // ดึงข้อมูลเตียงว่าง
    $availableResult = $conn->query("SELECT COUNT(*) as available FROM beds WHERE status = 'available'");
    if (!$availableResult) {
        $availableBeds = 0;
    } else {
        $availableRow = $availableResult->fetch_assoc();
        $availableBeds = isset($availableRow['available']) ? intval($availableRow['available']) : 0;
    }

    // ดึงข้อมูลเตียงที่มีผู้ป่วย
    $occupiedResult = $conn->query("SELECT COUNT(*) as occupied FROM beds WHERE status = 'occupied'");
    if (!$occupiedResult) {
        $occupiedBeds = 0;
    } else {
        $occupiedRow = $occupiedResult->fetch_assoc();
        $occupiedBeds = isset($occupiedRow['occupied']) ? intval($occupiedRow['occupied']) : 0;
    }

    // ดึงข้อมูลชั้นของ ICU
    $floorsResult = $conn->query("SELECT DISTINCT floor FROM beds ORDER BY floor");
    $floors = [];
    if ($floorsResult) {
        while ($row = $floorsResult->fetch_assoc()) {
            $floors[] = $row['floor'];
        }
    }
    $currentFloor = !empty($floors) ? intval($floors[0]) : 1;

    // จำนวนคิว (จากตาราง bed_bookings) — total และ วันนี้ (ถ้ามีคอลัมน์ created_at จะนับตามวันปัจจุบัน)
    $queueToday = 0;
    $totalQueue = 0;
    try {
        $qRes = $conn->query("SELECT COUNT(*) AS c FROM bed_bookings");
        if ($qRes) {
            $r = $qRes->fetch_assoc();
            $totalQueue = isset($r['c']) ? intval($r['c']) : 0;
        }
        // ถ้าตารางมี created_at ให้ใช้นับคำสั่งของวันนี้ (date)
        $qToday = $conn->query("SELECT COUNT(*) AS c FROM bed_bookings WHERE DATE(created_at) = CURDATE()");
        if ($qToday) {
            $rt = $qToday->fetch_assoc();
            $queueToday = isset($rt['c']) ? intval($rt['c']) : 0;
        }
    } catch (Exception $e) {
        // fallback 0
        $queueToday = 0;
        $totalQueue = 0;
    }

    // เวลาอัปเดตปัจจุบัน
    $lastUpdated = date('Y-m-d H:i:s');

    echo json_encode([
        'success' => true,
        'totalBeds' => $totalBeds,
        'availableBeds' => $availableBeds,
        'occupiedBeds' => $occupiedBeds,
        'currentFloor' => $currentFloor,
        'systemStatus' => 'ออนไลน์',
        'queueToday' => $queueToday,
        'totalQueue' => $totalQueue,
        'lastUpdated' => $lastUpdated
    ]);

} catch (Exception $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ]);
}

if (isset($conn)) {
    $conn->close();
}
?>
