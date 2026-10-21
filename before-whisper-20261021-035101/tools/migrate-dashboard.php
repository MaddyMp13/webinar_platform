<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/api/db.php';
$config = require dirname(__DIR__) . '/config/config.php';
$db = database($config);
$changes = [
    ['lead_webinars','activity_consent','ADD COLUMN activity_consent TINYINT(1) NOT NULL DEFAULT 0'],
    ['lead_webinars','activity_consent_updated_at','ADD COLUMN activity_consent_updated_at DATETIME(6) NULL'],
    ['playback_sessions','duration_seconds','ADD COLUMN duration_seconds DECIMAL(12,3) NULL'],
    ['playback_sessions','last_event_at','ADD COLUMN last_event_at DATETIME(6) NULL'],
    ['playback_events','watched_delta_seconds','ADD COLUMN watched_delta_seconds DECIMAL(8,3) NOT NULL DEFAULT 0'],
    ['chat_messages','request_uuid','ADD COLUMN request_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE'],
];
foreach ($changes as [$table,$column,$ddl]) {
    $query = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $query->execute([$table,$column]);
    if (!(int) $query->fetchColumn()) $db->exec("ALTER TABLE $table $ddl");
}
$db->exec("ALTER TABLE consent_events MODIFY consent_type ENUM('privacy','marketing','activity') NOT NULL");
$db->exec("CREATE TABLE IF NOT EXISTS admin_sessions (
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 admin_id BIGINT UNSIGNED NOT NULL,
 csrf_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 expires_at DATETIME(6) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX idx_admin_session_expiry(expires_at),
 CONSTRAINT fk_admin_session_user FOREIGN KEY(admin_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB");
$db->exec("INSERT IGNORE INTO schema_migrations(version) VALUES('002_activity_dashboard')");
echo "Dashboard migration applied. Existing records preserved.\n";
