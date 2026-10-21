-- Non-destructive migration for existing XAMPP databases. Run after schema.sql.
USE webinar_platform;
SET @chat_quote_ddl = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_message_sources' AND column_name='quote_text'),'SELECT 1','ALTER TABLE chat_message_sources ADD COLUMN quote_text TEXT NULL');
PREPARE chat_quote_stmt FROM @chat_quote_ddl;
EXECUTE chat_quote_stmt;
DEALLOCATE PREPARE chat_quote_stmt;
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
