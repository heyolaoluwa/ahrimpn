<?php
// api/backup.php — Monthly automated database backup
// Run via cron: 0 23 28-31 * * php /path/to/api/backup.php
// (cPanel will only execute it on the last day of each month using the guard below)

require_once __DIR__ . '/config.php';

// Only run on the last day of the current month
$today     = (int) date('j');
$lastDay   = (int) date('t');
$isCron    = PHP_SAPI === 'cli' || (isset($_SERVER['HTTP_X_BACKUP_KEY']) && $_SERVER['HTTP_X_BACKUP_KEY'] === BACKUP_KEY);

if (!$isCron) {
    http_response_code(403);
    exit('Forbidden');
}

if ($today !== $lastDay) {
    echo date('[Y-m-d H:i:s]') . " Not the last day of the month ($today/$lastDay). Skipping.\n";
    exit(0);
}

// ── Config ────────────────────────────────────────────────────
$backupDir = __DIR__ . '/../backups';
$month     = date('Y-m');
$filename  = "backup_{$month}.sql.gz";
$filepath  = "{$backupDir}/{$filename}";

// Create backups folder if it doesn't exist
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0750, true);

    // Protect the folder from direct web access
    file_put_contents($backupDir . '/.htaccess', "Deny from all\n");
}

// ── Run mysqldump ─────────────────────────────────────────────
$cmd = sprintf(
    'mysqldump --host=%s --user=%s --password=%s --single-transaction --routines --triggers %s | gzip > %s 2>&1',
    escapeshellarg(DB_HOST),
    escapeshellarg(DB_USER),
    escapeshellarg(DB_PASS),
    escapeshellarg(DB_NAME),
    escapeshellarg($filepath)
);

exec($cmd, $output, $exitCode);

if ($exitCode !== 0 || !file_exists($filepath) || filesize($filepath) < 100) {
    $msg = date('[Y-m-d H:i:s]') . " BACKUP FAILED for $month. Exit: $exitCode\n" . implode("\n", $output);
    error_log($msg);
    echo $msg;
    exit(1);
}

$size = round(filesize($filepath) / 1024, 1);
echo date('[Y-m-d H:i:s]') . " Backup complete: $filename ({$size} KB)\n";

// ── Remove backups older than 12 months ───────────────────────
$files = glob($backupDir . '/backup_*.sql.gz') ?: [];
if (count($files) > 12) {
    sort($files);
    $toDelete = array_slice($files, 0, count($files) - 12);
    foreach ($toDelete as $old) {
        unlink($old);
        echo date('[Y-m-d H:i:s]') . " Deleted old backup: " . basename($old) . "\n";
    }
}

exit(0);
