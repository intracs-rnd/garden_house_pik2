<?php
$master = new PDO('pgsql:host=192.168.214.161;port=5432;dbname=dashboard', 'postgres', 'GH001234');

$hbaPath = '/var/lib/postgresql/18/docker/pg_hba.conf';

// Baca isi file saat ini
$stmt = $master->query("SELECT pg_read_file('{$hbaPath}')");
$current = $stmt->fetchColumn();

// Cek apakah rule sudah ada
if (strpos($current, '192.168.214') !== false) {
    echo "Rule 192.168.214.x sudah ada di pg_hba.conf:\n";
    foreach(explode("\n", $current) as $line) {
        if (strpos($line, '192.168.214') !== false) echo "  $line\n";
    }
} else {
    echo "Menambah rule replication untuk 192.168.214.0/24...\n";
    // Tambah setelah baris "host all all all scram-sha-256"
    $newRule = "\n# Allow replication from local network (added for logical replication)\nhost    replication     all             192.168.214.0/24        scram-sha-256\nhost    all             all             192.168.214.0/24        scram-sha-256\n";
    $newContent = $current . $newRule;
    
    // pg_write_file tidak ada, gunakan adminpack extension
    try {
        $master->exec("CREATE EXTENSION IF NOT EXISTS adminpack");
    } catch(Exception $e) { /* mungkin sudah ada */ }
    
    $escapedContent = $master->quote($newContent);
    $master->exec("SELECT pg_file_write('{$hbaPath}', {$escapedContent}, false)");
    
    // Reload config
    $master->query("SELECT pg_reload_conf()");
    echo "  Done! pg_hba.conf diupdate dan di-reload.\n";
}

echo "\nVerifikasi rule baru:\n";
$stmt = $master->query("SELECT type, database, user_name, address, auth_method FROM pg_hba_file_rules WHERE address LIKE '192.168%' ORDER BY line_number");
$rows = $stmt->fetchAll();
if (empty($rows)) {
    echo "  Belum ada. Coba reload manual.\n";
} else {
    foreach($rows as $r) echo "  {$r['type']} db={$r['database']} addr={$r['address']} method={$r['auth_method']}\n";
}








echo "=== TABLES IN dashboard ===\n";
$stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename");
foreach($stmt as $r) echo "  " . $r['tablename'] . "\n";

echo "\n=== COLUMNS: cards ===\n";
$stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name='cards' ORDER BY ordinal_position");
foreach($stmt as $r) echo "  " . $r['column_name'] . " (" . $r['data_type'] . ")\n";

echo "\n=== COLUMNS: kartus ===\n";
$stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name='kartus' ORDER BY ordinal_position");
foreach($stmt as $r) echo "  " . $r['column_name'] . " (" . $r['data_type'] . ")\n";

echo "\n=== SUBSCRIPTION STATUS ===\n";
$stmt = $pdo->query("SELECT subname, subenabled, subpublications FROM pg_subscription");
foreach($stmt as $r) echo "  " . $r['subname'] . " enabled=" . $r['subenabled'] . " pub=" . $r['subpublications'] . "\n";
