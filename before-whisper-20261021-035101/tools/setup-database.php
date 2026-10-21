<?php
declare(strict_types=1);
// CLI only: php tools/setup-database.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require dirname(__DIR__) . '/config/config.php';
if (!preg_match('/^[A-Za-z0-9_]+$/D', $config['db_name'])) {
    fwrite(STDERR, "Use a database name containing only letters, digits, and underscores.\n"); exit(1);
}
try {
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['db_host'], $config['db_port']),
        $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    foreach (['schema.sql','seed.sql'] as $file) {
        $sql = file_get_contents(dirname(__DIR__) . '/database/' . $file);
        // The shipped SQL uses webinar_platform. This supports an isolated test database too.
        $sql = str_replace('webinar_platform', $config['db_name'], $sql);
        $pdo->exec($sql);
    }
    require __DIR__ . '/migrate-dashboard.php';
    require __DIR__ . '/migrate-chatbot.php';
    $count = $pdo->query('SELECT COUNT(*) FROM webinars')->fetchColumn();
    echo "Database {$config['db_name']} ready; $count webinars. Existing records were preserved.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Database setup failed. Check that XAMPP MySQL is running and config/config.php has the right local credentials.\n");
    fwrite(STDERR, 'Error code: ' . $error->getCode() . "\n"); exit(1);
}
