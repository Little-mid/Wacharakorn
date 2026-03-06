<?php
header('Content-Type: application/json; charset=utf-8');

// ตรวจสอบการเชื่อมต่อ
$conn = new mysqli('localhost', 'root', '', 'Room_management');

if ($conn->connect_error) {
    echo json_encode([
        'success' => false,
        'message' => 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้',
        'error' => $conn->connect_error,
        'tips' => [
            '1. ตรวจสอบว่า MySQL Server เปิดใช้งานอยู่',
            '2. ตรวจสอบความถูกต้องของ host, user, password',
            '3. ตรวจสอบว่าฐานข้อมูล Room_management มีอยู่',
            '4. เรียกใช้ SQL จาก database.sql เพื่อสร้างตารางทั้งหมด'
        ]
    ]);
    exit;
}

// ตรวจสอบตาราง
$tables = [];
$result = $conn->query("SHOW TABLES");
if ($result) {
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }
}

echo json_encode([
    'success' => true,
    'message' => 'เชื่อมต่อฐานข้อมูลสำเร็จ',
    'database' => 'Room_management',
    'tables' => $tables,
    'table_count' => count($tables),
    'required_tables' => ['users', 'patients', 'beds', 'bed_bookings'],
    'all_tables_exist' => count(array_intersect($tables, ['users', 'patients', 'beds', 'bed_bookings'])) === 4
]);

$conn->close();
?>
