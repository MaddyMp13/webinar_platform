-- Run ONCE after all six new sample-N.mp4 files have been uploaded.
-- Select the existing webinar database in phpMyAdmin first; no USE statement is needed.
-- Only assets still pointing at these six sample filenames are changed.
CREATE TEMPORARY TABLE replacement_samples (
  webinar_id BIGINT UNSIGNED PRIMARY KEY,
  storage_path VARCHAR(512) NOT NULL,
  label VARCHAR(255) NOT NULL,
  duration_seconds INT UNSIGNED NOT NULL
) ENGINE=MEMORY;
INSERT INTO replacement_samples (webinar_id,storage_path,label,duration_seconds) VALUES
  (1, 'sample-1.mp4', 'How a Single Webinar Outperformed 6 Months of Paid Ads!', 506),
  (2, 'sample-2.mp4', 'This Is Why You Can''t Get What You Want From People', 511),
  (3, 'sample-3.mp4', '5 Storytelling Mistakes That Are KILLING Small Creators', 879),
  (4, 'sample-4.mp4', 'Psychology of Verbal Intelligence: How To Articulate Your Thoughts Intelligently', 825),
  (5, 'sample-5.mp4', 'Why are students protesting in France? | BBC Learning English from the News', 409),
  (6, 'sample-6.mp4', 'I made $35M with webinars. Here''s what I learned in under 9 mins.', 538);

-- Stop without changing data when the selected database does not have all six
-- expected sample assets, or this migration has already been applied.
SET @matching_sample_assets = (
  SELECT COUNT(*) FROM webinar_assets a
  JOIN replacement_samples r ON r.webinar_id=a.webinar_id AND r.storage_path=a.storage_path
  WHERE a.asset_type='video'
);
DELETE FROM replacement_samples
WHERE @matching_sample_assets <> 6
   OR EXISTS (SELECT 1 FROM schema_migrations WHERE version='005_replace_sample_videos_20261022');

START TRANSACTION;
UPDATE transcription_jobs j
JOIN replacement_samples r ON r.webinar_id=j.webinar_id
JOIN webinar_assets a ON a.webinar_id=r.webinar_id AND a.asset_type='video' AND a.storage_path=r.storage_path
SET j.status='cancelled', j.phase='cancelled', j.message='Sample video replaced; start a new transcription.', j.finished_at=UTC_TIMESTAMP(6)
WHERE j.status IN ('queued','running');

DELETE i FROM webinar_insights i
JOIN replacement_samples r ON r.webinar_id=i.webinar_id
JOIN webinar_assets a ON a.webinar_id=r.webinar_id AND a.asset_type='video' AND a.storage_path=r.storage_path;
DELETE t FROM webinar_transcripts t
JOIN replacement_samples r ON r.webinar_id=t.webinar_id
JOIN webinar_assets a ON a.webinar_id=r.webinar_id AND a.asset_type='video' AND a.storage_path=r.storage_path;
DELETE d FROM webinar_transcript_documents d
JOIN replacement_samples r ON r.webinar_id=d.webinar_id
JOIN webinar_assets a ON a.webinar_id=r.webinar_id AND a.asset_type='video' AND a.storage_path=r.storage_path;

UPDATE webinars w
JOIN replacement_samples r ON r.webinar_id=w.id
JOIN webinar_assets a ON a.webinar_id=r.webinar_id AND a.asset_type='video' AND a.storage_path=r.storage_path
SET w.transcript_status='not_configured', w.duration_seconds=r.duration_seconds;
UPDATE webinar_assets a
JOIN replacement_samples r ON r.webinar_id=a.webinar_id AND r.storage_path=a.storage_path
SET a.label=r.label, a.is_demo=1, a.updated_at=UTC_TIMESTAMP(6)
WHERE a.asset_type='video';
INSERT IGNORE INTO schema_migrations(version)
SELECT '005_replace_sample_videos_20261022' FROM replacement_samples HAVING COUNT(*)=6;
COMMIT;

-- Existing registrations and playback analytics stay intact. Previous chats stay archived
-- in the database, while the app starts a fresh conversation for each replacement video.
SELECT a.webinar_id,a.storage_path,a.label,w.transcript_status,w.duration_seconds
FROM webinar_assets a JOIN webinars w ON w.id=a.webinar_id
JOIN replacement_samples r ON r.webinar_id=a.webinar_id AND r.storage_path=a.storage_path
WHERE a.asset_type='video' ORDER BY a.webinar_id;
SELECT @matching_sample_assets AS matching_sample_assets,
       EXISTS (SELECT 1 FROM schema_migrations WHERE version='005_replace_sample_videos_20261022') AS migration_applied;
DROP TEMPORARY TABLE replacement_samples;
