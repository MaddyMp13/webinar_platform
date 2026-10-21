-- Additive faster-whisper queue for an existing local XAMPP database.
USE webinar_platform;
CREATE TABLE IF NOT EXISTS transcription_jobs (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 webinar_id BIGINT UNSIGNED NOT NULL,
 asset_id BIGINT UNSIGNED NOT NULL,
 requested_by BIGINT UNSIGNED NULL,
 status ENUM('queued','running','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
 phase VARCHAR(40) NOT NULL DEFAULT 'queued',
 language_requested VARCHAR(8) NOT NULL DEFAULT 'auto',
 language_detected VARCHAR(8) NULL,
 language_probability DECIMAL(6,5) NULL,
 model_name VARCHAR(40) NOT NULL,
 source_path VARCHAR(512) NOT NULL,
 source_size BIGINT UNSIGNED NOT NULL,
 source_mtime BIGINT UNSIGNED NOT NULL,
 source_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 base_transcript_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 result_transcript_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 lease_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 worker_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 duration_seconds DECIMAL(12,3) NULL,
 processed_seconds DECIMAL(12,3) NOT NULL DEFAULT 0,
 segment_count INT UNSIGNED NOT NULL DEFAULT 0,
 message VARCHAR(500) NOT NULL DEFAULT 'Waiting for local transcription worker.',
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 started_at DATETIME(6) NULL,
 heartbeat_at DATETIME(6) NULL,
 finished_at DATETIME(6) NULL,
 INDEX idx_transcription_queue(status,created_at),
 INDEX idx_transcription_webinar(webinar_id,created_at),
 CONSTRAINT fk_transcription_webinar FOREIGN KEY(webinar_id) REFERENCES webinars(id) ON DELETE CASCADE,
 CONSTRAINT fk_transcription_asset FOREIGN KEY(asset_id) REFERENCES webinar_assets(id) ON DELETE CASCADE,
 CONSTRAINT fk_transcription_admin FOREIGN KEY(requested_by) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS transcription_worker_state (
 id TINYINT UNSIGNED PRIMARY KEY,
 worker_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 package_version VARCHAR(40) NOT NULL,
 state VARCHAR(30) NOT NULL,
 last_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;
INSERT IGNORE INTO schema_migrations(version) VALUES('004_faster_whisper');
