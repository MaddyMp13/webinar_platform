-- XAMPP / MariaDB 10.4+ / MySQL 8.0+
-- Import this file first, then seed.sql. No tables or existing rows are dropped.
CREATE DATABASE IF NOT EXISTS webinar_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE webinar_platform;
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(60) PRIMARY KEY,
  applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS webinars (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_code VARCHAR(40) NOT NULL UNIQUE,
  slug VARCHAR(160) NOT NULL UNIQUE,
  title VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  category VARCHAR(100) NOT NULL,
  image_url TEXT NULL,
  publisher_url TEXT NULL,
  published_label VARCHAR(80) NULL,
  published_at DATETIME(6) NULL,
  duration_seconds INT UNSIGNED NULL,
  transcript_status ENUM('not_configured','pending','processing','ready','failed') NOT NULL DEFAULT 'not_configured',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  INDEX idx_webinars_category (category, is_active),
  INDEX idx_webinars_published (published_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS webinar_assets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  webinar_id BIGINT UNSIGNED NOT NULL,
  asset_type ENUM('video','document') NOT NULL DEFAULT 'video',
  storage_path VARCHAR(512) NOT NULL COMMENT 'Relative to the configured private videos directory; never a public URL',
  label VARCHAR(255) NOT NULL,
  mime_type VARCHAR(80) NOT NULL DEFAULT 'video/mp4',
  is_demo TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_webinar_asset_type (webinar_id, asset_type),
  CONSTRAINT fk_assets_webinar FOREIGN KEY (webinar_id) REFERENCES webinars(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS leads (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  email VARCHAR(254) NOT NULL UNIQUE,
  phone VARCHAR(30) NOT NULL,
  company VARCHAR(120) NOT NULL,
  job_title VARCHAR(100) NOT NULL DEFAULT '',
  country CHAR(2) NOT NULL COMMENT 'ISO 3166-1 alpha-2 country code',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  INDEX idx_leads_company (company),
  INDEX idx_leads_country (country),
  INDEX idx_leads_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lead_webinars (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id BIGINT UNSIGNED NOT NULL,
  webinar_id BIGINT UNSIGNED NOT NULL,
  registration_status ENUM('registered','revoked') NOT NULL DEFAULT 'registered',
  privacy_consent TINYINT(1) NOT NULL,
  privacy_version VARCHAR(60) NOT NULL,
  privacy_accepted_at DATETIME(6) NOT NULL,
  marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,
  activity_consent TINYINT(1) NOT NULL DEFAULT 0,
  activity_consent_updated_at DATETIME(6) NULL,
  consent_updated_at DATETIME(6) NOT NULL,
  registered_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_lead_webinar (lead_id, webinar_id),
  UNIQUE KEY uq_registration_webinar (id, webinar_id),
  INDEX idx_registrations_webinar_date (webinar_id, registered_at),
  INDEX idx_registrations_updated (updated_at),
  CONSTRAINT fk_registration_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_registration_webinar FOREIGN KEY (webinar_id) REFERENCES webinars(id) ON DELETE CASCADE,
  CONSTRAINT chk_privacy_accepted CHECK (privacy_consent = 1),
  CONSTRAINT chk_marketing_boolean CHECK (marketing_opt_in IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS consent_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  registration_id BIGINT UNSIGNED NOT NULL,
  consent_type ENUM('privacy','marketing','activity') NOT NULL,
  consent_status TINYINT(1) NOT NULL,
  notice_version VARCHAR(60) NOT NULL,
  recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_consent_registration_time (registration_id, recorded_at),
  CONSTRAINT fk_consent_registration FOREIGN KEY (registration_id) REFERENCES lead_webinars(id) ON DELETE CASCADE,
  CONSTRAINT chk_consent_boolean CHECK (consent_status IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS visitor_sessions (
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  csrf_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_visitor_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS session_registrations (
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  webinar_id BIGINT UNSIGNED NOT NULL,
  registration_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (token_hash, webinar_id),
  INDEX idx_session_registration (registration_id, webinar_id),
  CONSTRAINT fk_session_visitor FOREIGN KEY (token_hash) REFERENCES visitor_sessions(token_hash) ON DELETE CASCADE,
  CONSTRAINT fk_session_registration FOREIGN KEY (registration_id, webinar_id) REFERENCES lead_webinars(id, webinar_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Activity is stored in separate event/message rows. No analytics or AI data is seeded.
CREATE TABLE IF NOT EXISTS playback_sessions (
  id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  registration_id BIGINT UNSIGNED NOT NULL,
  asset_id BIGINT UNSIGNED NULL,
  duration_seconds DECIMAL(12,3) NULL,
  last_event_at DATETIME(6) NULL,
  started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  ended_at DATETIME(6) NULL,
  last_position_seconds DECIMAL(12,3) NOT NULL DEFAULT 0,
  watched_seconds DECIMAL(12,3) NOT NULL DEFAULT 0,
  completion_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  status ENUM('created','playing','paused','completed','abandoned','ended') NOT NULL DEFAULT 'created',
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  INDEX idx_playback_registration_date (registration_id, started_at),
  CONSTRAINT fk_playback_registration FOREIGN KEY (registration_id) REFERENCES lead_webinars(id) ON DELETE CASCADE,
  CONSTRAINT fk_playback_asset FOREIGN KEY (asset_id) REFERENCES webinar_assets(id) ON DELETE SET NULL,
  CONSTRAINT chk_completion_range CHECK (completion_percent BETWEEN 0 AND 100),
  CONSTRAINT chk_watch_nonnegative CHECK (watched_seconds >= 0 AND last_position_seconds >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS playback_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  playback_session_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_type ENUM('started','playing','paused','resumed','seeked','progress','stopped','completed','session_ended') NOT NULL,
  position_seconds DECIMAL(12,3) NOT NULL DEFAULT 0,
  previous_position_seconds DECIMAL(12,3) NULL,
  new_position_seconds DECIMAL(12,3) NULL,
  progress_percent DECIMAL(5,2) NULL,
  occurred_at DATETIME(6) NOT NULL,
  received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  metadata JSON NULL,
  watched_delta_seconds DECIMAL(8,3) NOT NULL DEFAULT 0,
  INDEX idx_events_session_time (playback_session_id, occurred_at),
  INDEX idx_events_type_time (event_type, occurred_at),
  CONSTRAINT fk_event_session FOREIGN KEY (playback_session_id) REFERENCES playback_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS webinar_transcripts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  webinar_id BIGINT UNSIGNED NOT NULL,
  chunk_index INT UNSIGNED NOT NULL,
  chunk_text LONGTEXT NOT NULL,
  start_seconds DECIMAL(12,3) NULL,
  end_seconds DECIMAL(12,3) NULL,
  source_reference VARCHAR(512) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_transcript_chunk (webinar_id, chunk_index),
  CONSTRAINT fk_transcript_webinar FOREIGN KEY (webinar_id) REFERENCES webinars(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chat_conversations (
  id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  webinar_id BIGINT UNSIGNED NOT NULL,
  registration_id BIGINT UNSIGNED NULL,
  playback_session_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  INDEX idx_conversations_webinar_date (webinar_id, created_at),
  INDEX idx_conversations_registration (registration_id),
  CONSTRAINT fk_chat_webinar FOREIGN KEY (webinar_id) REFERENCES webinars(id) ON DELETE CASCADE,
  CONSTRAINT fk_chat_registration FOREIGN KEY (registration_id) REFERENCES lead_webinars(id) ON DELETE CASCADE,
  CONSTRAINT fk_chat_playback FOREIGN KEY (playback_session_id) REFERENCES playback_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chat_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  role ENUM('user','assistant','system') NOT NULL,
  message LONGTEXT NOT NULL,
  model_name VARCHAR(120) NULL,
  input_tokens INT UNSIGNED NULL,
  output_tokens INT UNSIGNED NULL,
  request_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_messages_conversation_order (conversation_id, id),
  CONSTRAINT fk_message_conversation FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chat_message_sources (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id BIGINT UNSIGNED NOT NULL,
  transcript_chunk_id BIGINT UNSIGNED NULL,
  source_label VARCHAR(255) NOT NULL,
  quote_text TEXT NULL,
  start_seconds DECIMAL(12,3) NULL,
  end_seconds DECIMAL(12,3) NULL,
  CONSTRAINT fk_source_message FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_source_chunk FOREIGN KEY (transcript_chunk_id) REFERENCES webinar_transcripts(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(254) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL COMMENT 'Use PHP password_hash(); never store a plaintext password',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS api_rate_limits (
  bucket_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  window_started_at DATETIME(6) NOT NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  INDEX idx_rate_window (window_started_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_sessions (
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  admin_id BIGINT UNSIGNED NOT NULL,
  csrf_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_admin_session_expiry (expires_at),
  CONSTRAINT fk_admin_session_user FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO schema_migrations(version) VALUES ('001_initial_mysql_schema');
INSERT IGNORE INTO schema_migrations(version) VALUES ('002_activity_dashboard');

-- Non-destructive migration for existing XAMPP databases. Run after schema.sql.
CREATE TABLE IF NOT EXISTS webinar_transcript_documents (
 webinar_id BIGINT UNSIGNED PRIMARY KEY,
 file_name VARCHAR(180) NOT NULL,
 source_format VARCHAR(8) NOT NULL,
 content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 chunk_count INT UNSIGNED NOT NULL,
 uploaded_by BIGINT UNSIGNED NULL,
 uploaded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 CONSTRAINT fk_document_webinar FOREIGN KEY(webinar_id) REFERENCES webinars(id) ON DELETE CASCADE,
 CONSTRAINT fk_document_admin FOREIGN KEY(uploaded_by) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS webinar_insights (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 webinar_id BIGINT UNSIGNED NOT NULL,
 content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 model_name VARCHAR(120) NOT NULL,
 part_index INT UNSIGNED NOT NULL,
 result_json LONGTEXT NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_insight_part(webinar_id,content_hash,model_name,part_index),
 CONSTRAINT fk_insights_webinar FOREIGN KEY(webinar_id) REFERENCES webinars(id) ON DELETE CASCADE
) ENGINE=InnoDB;
INSERT IGNORE INTO schema_migrations(version) VALUES('003_local_transcript_chatbot');

-- Import after schema.sql. Existing campaigns/assets are preserved. No leads, transcripts, or admin passwords are seeded.
USE webinar_platform;
SET NAMES utf8mb4;
SET time_zone = '+00:00';
START TRANSACTION;
INSERT IGNORE INTO webinars(id,campaign_code,slug,title,description,category,image_url,publisher_url,published_label) VALUES (1,'CAMP001','ai-data-resilience','AI & Data Resilience','Explore how AI and data resilience help organizations build secure and intelligent systems.','AI','https://resources.leadershipconnect.io/hubfs/Webinar%20Graphic%20%26%20Thumbnail%20-%2020260319.png','https://resources.leadershipconnect.io/data-ai-and-digital-resilience-building-secure-intelligent-systems-for-a-complex-world','March 19, 2026');
INSERT IGNORE INTO webinar_assets(webinar_id,asset_type,storage_path,label,is_demo) VALUES (1, 'video', 'sample-1.mp4', 'Flower — sample video', 1);
INSERT IGNORE INTO webinars(id,campaign_code,slug,title,description,category,image_url,publisher_url,published_label) VALUES (2,'CAMP002','cloud-security-ai','Cloud Security & AI','Learn how to secure cloud infrastructure in an AI-driven business environment.','Cloud','https://storage.googleapis.com/streamyard-app/public/onair/cloudStorageItem_Z3AAVw6NfOJvim4a.png','https://streamyard.com/watch/68xD33BRktmf','2026');
INSERT IGNORE INTO webinar_assets(webinar_id,asset_type,storage_path,label,is_demo) VALUES (2, 'video', 'sample-2.mp4', 'Big Buck Bunny — sample clip', 1);
INSERT IGNORE INTO webinars(id,campaign_code,slug,title,description,category,image_url,publisher_url,published_label) VALUES (3,'CAMP003','cloud-data-analytics','Cloud Data & Analytics','Discover how cloud data, AI, and analytics can improve public safety and decision-making.','Data Analytics','https://media.erepublic.com/image/EM23-HEADERS-Webinar-Informatica-6-14-1200x630.jpg','https://webinars.govtech.com/Advancing-Public-Safety-with-Cloud%2C-Data%2C-AI-and-Analytics-141906.html','2024');
INSERT IGNORE INTO webinar_assets(webinar_id,asset_type,storage_path,label,is_demo) VALUES (3, 'video', 'sample-3.mp4', 'Sintel — sample trailer', 1);
INSERT IGNORE INTO webinars(id,campaign_code,slug,title,description,category,image_url,publisher_url,published_label) VALUES (4,'CAMP004','cybersecurity-ai','Cybersecurity & AI','Understand how data-driven approaches and AI can strengthen cybersecurity.','Cybersecurity','https://media.erepublic.com/image/GT24-HEADERS-Webinar-Mandiant-12-18-1200x630.jpg','https://webinars.govtech.com/Revolutionizing-State-and-Local-Cybersecurity%3A-A-Data-Driven%2C-AI-Powered-Approach-142754.html','2024');
INSERT IGNORE INTO webinar_assets(webinar_id,asset_type,storage_path,label,is_demo) VALUES (4, 'video', 'sample-4.mp4', 'Big Buck Bunny — sample trailer', 1);
INSERT IGNORE INTO webinars(id,campaign_code,slug,title,description,category,image_url,publisher_url,published_label) VALUES (5,'CAMP005','big-data-microsoft-azure','Big Data & Microsoft Azure','Explore advanced analytics, big data processing, and AI insights using Synapse.','Big Data','https://www.synergetics-india.com/uploads/events/gallery/pic--230.jpg','https://www.synergetics-india.com/events/advanced-analytics-with-synapse-big-data-processing-and-ai-insights-244','2024');
INSERT IGNORE INTO webinar_assets(webinar_id,asset_type,storage_path,label,is_demo) VALUES (5, 'video', 'sample-5.mp4', 'W3C — sample video', 1);
INSERT IGNORE INTO webinars(id,campaign_code,slug,title,description,category,image_url,publisher_url,published_label) VALUES (6,'CAMP006','ai-threat-detection','AI Threat Detection','Learn about the relationship between artificial intelligence and modern cybersecurity threats.','Cybersecurity','https://media.licdn.com/dms/image/v2/D5610AQFRCwIpaUXv0g/image-shrink_800/B56ZpUY48jIsAg-/0/1762352403542?e=2147483647&t=DuM6YgCkDoIMwYTql3ZtBiNY-iBUfbWvfOxmL_q7970&v=beta','https://www.gartner.com/en/information-technology/events/webinars','2025');
INSERT IGNORE INTO webinar_assets(webinar_id,asset_type,storage_path,label,is_demo) VALUES (6, 'video', 'sample-6.mp4', 'Sintel — HD sample trailer', 1);
COMMIT;
