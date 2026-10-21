<?php
declare(strict_types=1);
// Optional one-time migration of legacy Node/SQLite registrations. CLI only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/api/bootstrap.php';
require dirname(__DIR__) . '/api/validation.php';
$sourcePath = $argv[1] ?? dirname(__DIR__) . '/.data/webinars.sqlite';
if (!is_file($sourcePath)) { fwrite(STDERR, "Legacy SQLite file not found. Pass its full path as the argument.\n"); exit(1); }
$config = require dirname(__DIR__) . '/config/config.php';
$db = null;
try {
    $source = new PDO('sqlite:' . $sourcePath, null, null, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY,
    ]);
    $source->exec('PRAGMA query_only = ON');
    $rows = $source->query('SELECT r.*, l.first_name,l.last_name,l.email,l.phone,l.company,l.job_title,l.country,l.created_at AS lead_created_at FROM lead_webinars r JOIN leads l ON l.id=r.lead_id')->fetchAll();
    $db = database($config);
    $db->beginTransaction();
    $imported = 0; $skipped = 0;
    $utc = static fn(string $date): string => (new DateTimeImmutable($date))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    foreach ($rows as $row) {
        $query = $db->prepare('SELECT id FROM webinars WHERE id=?');
        $query->execute([$row['webinar_id']]);
        if (!$query->fetchColumn()) { $skipped++; continue; }
        $data = registration_values([
            'firstName'=>$row['first_name'],'lastName'=>$row['last_name'],'email'=>$row['email'],
            'phone'=>$row['phone'],'company'=>$row['company'],'jobTitle'=>$row['job_title'],'country'=>$row['country'],
            'privacyConsent'=>(bool) $row['privacy_consent'],'marketingOptIn'=>(bool) $row['marketing_opt_in'],
        ]);
        $query = $db->prepare('INSERT IGNORE INTO leads(first_name,last_name,email,phone,company,job_title,country,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');
        $query->execute([$data['firstName'],$data['lastName'],$data['email'],$data['phone'],$data['company'],$data['jobTitle'],$data['country'],$utc($row['lead_created_at']),$utc($row['updated_at'])]);
        $query = $db->prepare('SELECT id FROM leads WHERE email=?');
        $query->execute([$data['email']]);
        $leadId = (int) $query->fetchColumn();
        $query = $db->prepare('SELECT id FROM lead_webinars WHERE lead_id=? AND webinar_id=?');
        $query->execute([$leadId,$row['webinar_id']]);
        if ($query->fetchColumn()) { $skipped++; continue; }
        $query = $db->prepare('INSERT INTO lead_webinars(lead_id,webinar_id,privacy_consent,privacy_version,privacy_accepted_at,marketing_opt_in,consent_updated_at,registered_at,updated_at) VALUES(?,?,1,?,?,?,?,?,?)');
        $query->execute([$leadId,$row['webinar_id'],$row['privacy_version'],$utc($row['privacy_accepted_at']),(int) $data['marketingOptIn'],$utc($row['consent_updated_at']),$utc($row['registered_at']),$utc($row['updated_at'])]);
        $registrationId = (int) $db->lastInsertId();
        $query = $db->prepare('INSERT INTO consent_events(registration_id,consent_type,consent_status,notice_version,recorded_at) VALUES(?,?,?,?,?)');
        $query->execute([$registrationId,'privacy',1,$row['privacy_version'],$utc($row['privacy_accepted_at'])]);
        $query->execute([$registrationId,'marketing',(int) $data['marketingOptIn'],$row['privacy_version'],$utc($row['consent_updated_at'])]);
        $imported++;
    }
    $db->commit();
    echo "Imported $imported registration(s); skipped $skipped existing/unmapped registration(s). Source SQLite file unchanged.\n";
} catch (Throwable $error) {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'Migration failed; target changes rolled back. Error type: ' . get_class($error) . "\n"); exit(1);
}
