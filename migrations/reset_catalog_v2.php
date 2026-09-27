<?php declare(strict_types=1);

// Explicit, offline v2 transition. This intentionally does not reinterpret
// legacy numeric ratings as cumulative compatibility states.
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n"); exit(2);
}
$options = getopt('', ['database:', 'confirm-reset:']);
$database = $options['database'] ?? '';
if (!is_string($database) || $database === '' ||
    ($options['confirm-reset'] ?? '') !== 'RESET-ACTIVE-CATALOG') {
    fwrite(STDERR, "Usage: php migrations/reset_catalog_v2.php --database=/absolute/app_db.sqlite3 --confirm-reset=RESET-ACTIVE-CATALOG\n");
    exit(2);
}
$database = realpath($database);
if ($database === FALSE || !is_file($database)) {
    fwrite(STDERR, "Database does not exist.\n"); exit(2);
}
$directory = dirname($database);
$stamp = gmdate('Ymd-His');
$backup = $database . '.pre-v2-' . $stamp . '.bak';
$export = $database . '.pre-v2-' . $stamp . '.json';
$replacement = $directory . DIRECTORY_SEPARATOR . basename($database) . '.v2-new-' . $stamp;
$rollback = $database . '.pre-v2-' . $stamp . '.sqlite3';

$old = new PDO('sqlite:' . $database);
$old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$tables = $old->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
    ->fetchAll(PDO::FETCH_COLUMN);
$archive = ['format'=>'tapHLEdb-pre-compatibility-v2','created_at'=>gmdate('c'),'tables'=>[]];
foreach ($tables as $table) {
    if (!preg_match('/\A[a-z_]+\z/', (string)$table)) throw new RuntimeException('Unsafe table name');
    $rows = $old->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        foreach ($row as $key=>&$value) {
            if (is_string($value) && preg_match('//u', $value) !== 1) {
                $value = ['base64'=>base64_encode($value)];
            }
        }
        unset($value);
    }
    unset($row);
    $archive['tables'][$table] = $rows;
}
$json = json_encode($archive, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
if (!copy($database, $backup) || file_put_contents($export, $json . "\n", LOCK_EX) === FALSE) {
    throw new RuntimeException('Could not write backup/export; active database was not changed.');
}

$new = new PDO('sqlite:' . $replacement);
$new->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$schema = file_get_contents(__DIR__ . '/../schema.sql');
if ($schema === FALSE) throw new RuntimeException('Could not read schema.sql');
$new->exec($schema);
if (in_array('users', $tables, TRUE)) {
    $insert = $new->prepare('INSERT INTO users(user_id,external_user_id,external_username) VALUES(:id,:external_id,:username)');
    foreach ($old->query('SELECT user_id,external_user_id,external_username FROM users') as $user) {
        $insert->execute([':id'=>$user['user_id'],':external_id'=>$user['external_user_id'],':username'=>$user['external_username']]);
    }
    $insert = NULL;
}
$new = NULL; $old = NULL;
if (!rename($database, $rollback)) {
    @unlink($replacement);
    throw new RuntimeException('Could not move active database; backup/export remain available.');
}
if (!rename($replacement, $database)) {
    rename($rollback, $database);
    throw new RuntimeException('Could not activate v2 database; original was restored.');
}
fwrite(STDOUT, "Activated empty v2 catalog.\nBackup: $backup\nJSON export: $export\nRollback database: $rollback\n");
