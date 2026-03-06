<?php
// ฐาน HOSxP (เซิร์ฟเวอร์ รพ.)
define('HOSXP_HOST', '192.168.10.14');
define('HOSXP_USER', 'kmp');
define('HOSXP_PASS', 'kmp');
define('HOSXP_NAME', 'hosxp');

$hosxpConn = new mysqli(HOSXP_HOST, HOSXP_USER, HOSXP_PASS, HOSXP_NAME);

if ($hosxpConn->connect_error) {
    // เมื่อ include ในสคริปต์อื่น ให้ปิดการทำงานด้วยข้อผิดพลาดแบบเงียบ
    // ผู้เรียกใช้งานต้องตรวจสอบ $hosxpConn เพื่อดูว่าเชื่อมต่อสำเร็จหรือไม่
    $hosxpConn = null;
} else {
    $hosxpConn->set_charset("utf8mb4");
}
?>