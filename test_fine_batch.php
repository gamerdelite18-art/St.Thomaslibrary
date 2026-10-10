<?php
/**
 * Checks student_fine_summaries() (the batch version) returns exactly the same
 * numbers as calling student_fine_summary() once per student, and reports the
 * query count for each approach.
 */
require_once __DIR__ . '/../includes/functions.php';

$ids = array_map('intval', array_column(db_all('SELECT id FROM students ORDER BY id'), 'id'));
echo 'students: ' . count($ids) . "\n\n";

// --- per-student (the old, N+1 way) ---
$t0 = microtime(true);
$one = [];
foreach ($ids as $id) {
    $one[$id] = student_fine_summary($id);
}
$t1 = microtime(true);

// --- batch (the new way) ---
$batch = student_fine_summaries($ids);
$t2 = microtime(true);

$mismatch = 0;
foreach ($ids as $id) {
    $a = $one[$id];
    $b = $batch[$id] ?? null;
    if ($b === null) {
        echo "  MISSING in batch: student $id\n";
        $mismatch++;
        continue;
    }
    foreach (['assessed', 'collected', 'pending', 'overdue'] as $k) {
        if ((float) $a[$k] !== (float) $b[$k]) {
            printf("  MISMATCH student %d %s: per-student=%s batch=%s\n", $id, $k, $a[$k], $b[$k]);
            $mismatch++;
        }
    }
}

echo "\nper-student loop : " . count($ids) . " students, " . round(($t1 - $t0) * 1000) . " ms\n";
echo "batch helper     : " . count($ids) . " students, " . round(($t2 - $t1) * 1000) . " ms\n";
echo "differences      : $mismatch\n";

// empty input must not blow up
$empty = student_fine_summaries([]);
echo "empty input      : " . (count($empty) === 0 ? 'OK (empty array)' : 'FAIL') . "\n";

// a student with nothing at all
$none = student_fine_summaries([999999]);
echo "unknown student  : " . json_encode($none[999999]) . "\n";

echo "\n" . ($mismatch === 0 ? "RESULT: batch matches per-student exactly\n" : "RESULT: MISMATCHES FOUND\n");
exit($mismatch === 0 ? 0 : 1);
