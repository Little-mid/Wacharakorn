
<?php
// =====================================================
// bed_reservation.php (SAFE VERSION)
// แยกจาก process.php
// จัดการเฉพาะเตียงเท่านั้น
// ❌ ไม่มีการ INSERT cancellations เด็ดขาด
// =====================================================

require_once 'db_connect.php'; // ใช้ไฟล์เชื่อม DB เดิมของคุณ

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// -------------------------------
// ดึงเตียงทั้งหมด
// -------------------------------
if ($action === 'get_beds') {

    $rows = [];
    $r = $conn->query("SELECT bed_number, status FROM beds ORDER BY bed_number ASC");

    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    echo json_encode([
        "success" => true,
        "beds" => $rows
    ]);
    exit;
}


// -------------------------------
// จองเตียง
// -------------------------------
if ($action === 'book_bed') {

    $bed = intval($_POST['bed_number'] ?? 0);

    if ($bed <= 0) {
        echo json_encode(["success"=>false,"message"=>"invalid bed"]);
        exit;
    }

    $stmt = $conn->prepare("UPDATE beds SET status='occupied' WHERE bed_number=?");
    $stmt->bind_param("i", $bed);
    $stmt->execute();

    echo json_encode(["success"=>true]);
    exit;
}


// -------------------------------
// ปล่อยเตียง (ตอน done)
// ❗ แค่ update เตียงเท่านั้น
// -------------------------------
if ($action === 'release_bed') {

    $bed = intval($_POST['bed_number'] ?? 0);

    if ($bed <= 0) {
        echo json_encode(["success"=>false]);
        exit;
    }

    $stmt = $conn->prepare("UPDATE beds SET status='available' WHERE bed_number=?");
    $stmt->bind_param("i", $bed);
    $stmt->execute();

    echo json_encode(["success"=>true]);
    exit;
}

echo json_encode(["success"=>false,"message"=>"unknown action"]);
