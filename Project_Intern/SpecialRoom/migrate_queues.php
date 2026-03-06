<?php
/**
 * migrate_queues.php
 *
 * CLI utility to migrate stale public_requests (queue_date < CURDATE()) into today's queue,
 * assigning new daily queue_no values sequentially.
 *
 * Usage:
 *   php migrate_queues.php        # run migration
 *   php migrate_queues.php --dry-run   # show changes without updating
 *
 * Notes:
 * - Uses MySQL GET_LOCK('migrate_public_requests') to avoid concurrent runs.
 * - Recommend running nightly (e.g., 00:01) via Windows Task Scheduler or cron.
 */

require_once __DIR__ . '/config.php';

$dryRun = false;
$argv = $_SERVER['argv'] ?? [];
if (in_array('--dry-run', $argv, true) || in_array('-n', $argv, true)) $dryRun = true;

if (!isset($conn) || !$conn) {
    fwrite(STDERR, "DB connection not available (check config.php)\n");
    exit(2);
}

function fatal($msg, $code = 1) {
    fwrite(STDERR, $msg . "\n");
    exit($code);
}

function migrate_stale_public_requests($conn, $statuses = ['pending'], $dryRun = false) {
    // attempt advisory lock
    $lockRes = $conn->query("SELECT GET_LOCK('migrate_public_requests', 10) AS lk");
    if (!$lockRes) return ['ok' => false, 'message' => 'get_lock failed'];
    $lkRow = $lockRes->fetch_assoc();
    if (empty($lkRow['lk'])) return ['ok' => false, 'message' => 'could not acquire lock'];

    $changed = [];
    try {
        if (!$dryRun) $conn->begin_transaction();

        // compute current max queue_no for today
        $r = $conn->query("SELECT COALESCE(MAX(queue_no), 0) AS mx FROM public_requests WHERE queue_date = CURDATE()");
        $mx = 0;
        if ($r && ($rr = $r->fetch_assoc())) $mx = intval($rr['mx']);

        // build status list safely
        $esc = array_map(function($s) use ($conn){ return "'" . $conn->real_escape_string($s) . "'"; }, $statuses);
        $statusList = implode(',', $esc);

        // select stale rows ordered deterministically
        $sql = "SELECT id, queue_date, queue_no, created_at FROM public_requests WHERE queue_date < CURDATE() AND status IN ($statusList) ORDER BY queue_date, created_at, id";
        $res = $conn->query($sql);
        if (!$res) {
            if (!$dryRun) $conn->rollback();
            $conn->query("SELECT RELEASE_LOCK('migrate_public_requests')");
            return ['ok' => false, 'message' => 'select stale failed: ' . $conn->error];
        }

        if ($res->num_rows === 0) {
            if (!$dryRun) $conn->commit();
            $conn->query("SELECT RELEASE_LOCK('migrate_public_requests')");
            return ['ok' => true, 'message' => 'no stale rows'];
        }

        // prepare update
        $updStmt = null;
        if (!$dryRun) {
            $updStmt = $conn->prepare("UPDATE public_requests SET queue_date = CURDATE(), queue_no = ?, updated_at = NOW() WHERE id = ?");
            if (!$updStmt) {
                $conn->rollback();
                $conn->query("SELECT RELEASE_LOCK('migrate_public_requests')");
                return ['ok' => false, 'message' => 'prepare update failed: ' . $conn->error];
            }
        }

        while ($row = $res->fetch_assoc()) {
            $oldId = intval($row['id']);
            $oldDate = $row['queue_date'];
            $oldQ = $row['queue_no'] === null ? 'NULL' : intval($row['queue_no']);
            $mx++;
            $newQ = $mx;
            $changed[] = ['id' => $oldId, 'old_queue_date' => $oldDate, 'old_queue_no' => $oldQ, 'new_queue_no' => $newQ];

            if (!$dryRun) {
                $updStmt->bind_param('ii', $newQ, $oldId);
                if (!$updStmt->execute()) {
                    $updStmt->close();
                    $conn->rollback();
                    $conn->query("SELECT RELEASE_LOCK('migrate_public_requests')");
                    return ['ok' => false, 'message' => 'update failed for id ' . $oldId . ': ' . $conn->error];
                }
            }
        }

        if (!$dryRun) {
            $updStmt->close();
            $conn->commit();
        }

    } catch (Throwable $e) {
        if (!$dryRun) $conn->rollback();
        $conn->query("SELECT RELEASE_LOCK('migrate_public_requests')");
        return ['ok' => false, 'message' => 'exception: ' . $e->getMessage()];
    }

    // release lock
    $conn->query("SELECT RELEASE_LOCK('migrate_public_requests')");
    return ['ok' => true, 'changed' => $changed];
}

echo "migrate_queues.php (dry-run=" . ($dryRun ? '1' : '0') . ")\n";
$r = migrate_stale_public_requests($conn, ['pending'], $dryRun);
if (!$r['ok']) {
    fwrite(STDERR, "Failed: " . ($r['message'] ?? 'unknown') . "\n");
    exit(3);
}

if (empty($r['changed'])) {
    echo "No stale rows to migrate.\n";
    exit(0);
}

echo "Rows to migrate: " . count($r['changed']) . "\n";
foreach ($r['changed'] as $c) {
    echo sprintf("id=%d: %s -> queue_no %s => %d\n", $c['id'], $c['old_queue_date'], $c['old_queue_no'], $c['new_queue_no']);
}

if ($dryRun) {
    echo "Dry run complete. No changes applied.\n";
} else {
    echo "Migration applied successfully.\n";
}

exit(0);
