<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$sourcePath = databasePath();
if (!is_file($sourcePath)) {
    fwrite(STDERR, "SQLite source database not found: {$sourcePath}\n");
    exit(1);
}

$tables = [
    'users',
    'businesses',
    'customers',
    'products',
    'invoices',
    'invoice_items',
    'invoice_payments',
    'invoice_events',
    'quotations',
    'quotation_items',
    'quotation_events',
    'customer_portal_tokens',
    'quotation_portal_tokens',
    'expenses',
    'login_attempts',
    'ai_usage',
    'admin_audit_log',
    'plans',
    'subscriptions',
    'subscription_events',
    'subscription_transactions',
    'subscription_usage',
];

try {
    $source = new PDO('sqlite:' . $sourcePath);
    $source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $source->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $source->exec('PRAGMA foreign_keys = ON');
    initializeDatabase($source, false, false);

    $target = openDatabase();
    if (databaseDriver($target) !== 'mysql') {
        throw new RuntimeException('Set the database driver to mysql before running this importer.');
    }
    initializeDatabase($target, false, false);

    foreach ($tables as $table) {
        $count = (int) $target->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        if ($count !== 0) {
            throw new RuntimeException("Target table {$table} is not empty. No data was imported; choose a fresh MySQL database.");
        }
    }

    $target->beginTransaction();
    $copiedRows = 0;
    $expectedCounts = [];
    foreach ($tables as $table) {
        $select = $source->query("SELECT * FROM `{$table}`");
        $columns = [];
        for ($columnIndex = 0; $columnIndex < $select->columnCount(); $columnIndex++) {
            $metadata = $select->getColumnMeta($columnIndex);
            if (!is_array($metadata) || !isset($metadata['name'])) {
                throw new RuntimeException("Could not read the SQLite column metadata for {$table}.");
            }
            $columns[] = (string) $metadata['name'];
        }

        if (!$columns) {
            continue;
        }

        $quotedColumns = implode(', ', array_map(static fn (string $column): string => "`{$column}`", $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $insert = $target->prepare("INSERT INTO `{$table}` ({$quotedColumns}) VALUES ({$placeholders})");
        $expectedCounts[$table] = 0;
        while ($row = $select->fetch(PDO::FETCH_NUM)) {
            $insert->execute($row);
            $copiedRows++;
            $expectedCounts[$table]++;
        }
    }

    foreach ($expectedCounts as $table => $expectedCount) {
        $actualCount = (int) $target->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        if ($actualCount !== $expectedCount) {
            throw new RuntimeException("Verification failed for {$table}: expected {$expectedCount} rows, found {$actualCount}.");
        }
    }
    $target->commit();
    fwrite(STDOUT, "MySQL import complete. Copied {$copiedRows} rows across " . count($tables) . " tables.\n");
    fwrite(STDOUT, "Your SQLite database was left unchanged.\n");
} catch (Throwable $exception) {
    if (isset($target) && $target instanceof PDO && $target->inTransaction()) {
        $target->rollBack();
    }
    fwrite(STDERR, 'MySQL import failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
