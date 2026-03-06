<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'Room_management');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    // ส่ง JSON error สำหรับ AJAX requests
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้',
        'error' => $conn->connect_error,
        'tips' => [
            '✓ MySQL Server กำลังทำงาน',
            '✓ ฐานข้อมูล Room_management มีอยู่',
            '✓ ใช้ http://localhost/ICU/login.html (ไม่ใช่ file://)'
        ]
    ]);
    exit;
}

// Set charset to utf8mb4
$conn->set_charset("utf8mb4");
?>
