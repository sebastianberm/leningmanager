<?php
/** Simple migrations helper
 * Migration files live in includes/migrations/*.php and must return an array with keys:
 *  - name: string
 *  - check: function(PDO $db): bool   // return true if migration is needed
 *  - up: function(PDO $db): void     // perform migration
 */

function migrations_dir() {
    return __DIR__ . '/migrations';
}

function ensure_migrations_table(PDO $db) {
    $db->exec("CREATE TABLE IF NOT EXISTS migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL);");
}

function load_migration_file($path) {
    if (!file_exists($path)) return null;
    return include $path;
}

function list_migration_files() {
    $dir = migrations_dir();
    if (!is_dir($dir)) return [];
    $files = glob($dir . '/*.php');
    sort($files);
    return $files;
}

function get_applied_migrations(PDO $db) {
    ensure_migrations_table($db);
    $stmt = $db->query("SELECT name FROM migrations");
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    return $rows ?: [];
}

function pending_migrations(PDO $db) {
    $files = list_migration_files();
    $applied = get_applied_migrations($db);
    $pending = [];
    foreach ($files as $f) {
        $m = load_migration_file($f);
        if (!$m || empty($m['name']) || !is_callable($m['check']) ) continue;
        if (in_array($m['name'], $applied, true)) continue;
        try {
            if ($m['check']($db)) {
                $pending[] = $m['name'];
            }
        } catch (Exception $e) {
            // if check fails, consider it pending to be safe
            $pending[] = $m['name'];
        }
    }
    return $pending;
}

function apply_pending_migrations(PDO $db) {
    $files = list_migration_files();
    $applied = get_applied_migrations($db);
    $results = [];
    foreach ($files as $f) {
        $m = load_migration_file($f);
        if (!$m || empty($m['name']) || !is_callable($m['up']) ) continue;
        if (in_array($m['name'], $applied, true)) { $results[$m['name']] = 'already_applied'; continue; }
        // run check first
        $needed = true;
        if (is_callable($m['check'])) {
            try { $needed = $m['check']($db); } catch (Exception $e) { $needed = true; }
        }
        if (!$needed) {
            // mark as applied to avoid future runs
            $stmt = $db->prepare("INSERT OR REPLACE INTO migrations(name, applied_at) VALUES(?,?)");
            $stmt->execute([$m['name'], date('c')]);
            $results[$m['name']] = 'not_needed_marked';
            continue;
        }
        try {
            $db->beginTransaction();
            $m['up']($db);
            $stmt = $db->prepare("INSERT OR REPLACE INTO migrations(name, applied_at) VALUES(?,?)");
            $stmt->execute([$m['name'], date('c')]);
            $db->commit();
            $results[$m['name']] = 'applied';
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $results[$m['name']] = 'failed: ' . $e->getMessage();
        }
    }
    return $results;
}

?>
