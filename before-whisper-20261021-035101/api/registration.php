<?php
declare(strict_types=1);
require_once __DIR__ . '/validation.php';

function find_registration(PDO $db, ?array $session, int $webinarId): ?array
{
    if (!$session) return null;
    $query = $db->prepare("SELECT r.*, l.first_name,l.last_name,l.email,l.phone,l.company,l.job_title,l.country
      FROM session_registrations s JOIN lead_webinars r ON r.id=s.registration_id
      JOIN leads l ON l.id=r.lead_id
      WHERE s.token_hash=? AND s.webinar_id=? AND r.registration_status='registered'");
    $query->execute([$session['token_hash'], $webinarId]);
    $row = $query->fetch();
    if (!$row) return null;
    return [
        'id'=>(int) $row['id'], 'webinarId'=>(int) $row['webinar_id'],
        'firstName'=>$row['first_name'], 'lastName'=>$row['last_name'], 'email'=>$row['email'],
        'phone'=>$row['phone'], 'company'=>$row['company'], 'jobTitle'=>$row['job_title'], 'country'=>$row['country'],
        'privacyConsent'=>(bool) $row['privacy_consent'], 'marketingOptIn'=>(bool) $row['marketing_opt_in'],
        'activityConsent'=>(bool) $row['activity_consent'],
        'registeredAt'=>str_replace(' ', 'T', $row['registered_at']) . 'Z', 'videoUrl'=>api_url('video', $webinarId),
    ];
}

function save_registration(PDO $db, array $session, int $webinarId, array $raw): never
{
    $data = registration_values($raw);
    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    $db->beginTransaction();
    try {
        $query = $db->prepare('INSERT INTO leads(first_name,last_name,email,phone,company,job_title,country)
          VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),first_name=VALUES(first_name),
          last_name=VALUES(last_name),phone=VALUES(phone),company=VALUES(company),job_title=VALUES(job_title),country=VALUES(country)');
        $query->execute([$data['firstName'],$data['lastName'],$data['email'],$data['phone'],$data['company'],$data['jobTitle'],$data['country']]);
        $leadId = (int) $db->lastInsertId();
        $query = $db->prepare('SELECT * FROM lead_webinars WHERE lead_id=? AND webinar_id=? FOR UPDATE');
        $query->execute([$leadId, $webinarId]);
        $previous = $query->fetch();
        $query = $db->prepare("INSERT INTO lead_webinars(lead_id,webinar_id,privacy_consent,privacy_version,
          privacy_accepted_at,marketing_opt_in,consent_updated_at,activity_consent,activity_consent_updated_at) VALUES(?,?,1,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),registration_status='registered',
          privacy_accepted_at=IF(privacy_version<>VALUES(privacy_version),VALUES(privacy_accepted_at),privacy_accepted_at),
          privacy_version=VALUES(privacy_version),marketing_opt_in=VALUES(marketing_opt_in),
          consent_updated_at=VALUES(consent_updated_at),updated_at=VALUES(consent_updated_at),
          activity_consent=VALUES(activity_consent),activity_consent_updated_at=VALUES(activity_consent_updated_at)");
        $activityConsent = ($raw['activityConsent'] ?? false) === true;
        $query->execute([$leadId,$webinarId,PRIVACY_VERSION,$now,(int) $data['marketingOptIn'],$now,(int) $activityConsent,$now]);
        $registrationId = (int) $db->lastInsertId();
        $recordConsent = $db->prepare('INSERT INTO consent_events(registration_id,consent_type,consent_status,notice_version) VALUES(?,?,?,?)');
        if (!$previous || $previous['privacy_version'] !== PRIVACY_VERSION)
            $recordConsent->execute([$registrationId,'privacy',1,PRIVACY_VERSION]);
        if (!$previous || (bool) $previous['marketing_opt_in'] !== $data['marketingOptIn'])
            $recordConsent->execute([$registrationId,'marketing',(int) $data['marketingOptIn'],PRIVACY_VERSION]);
        if (!$previous || (bool) $previous['activity_consent'] !== $activityConsent)
            $recordConsent->execute([$registrationId,'activity',(int) $activityConsent,PRIVACY_VERSION]);
        $query = $db->prepare('INSERT INTO session_registrations(token_hash,webinar_id,registration_id) VALUES(?,?,?)
          ON DUPLICATE KEY UPDATE registration_id=VALUES(registration_id)');
        $query->execute([$session['token_hash'],$webinarId,$registrationId]);
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    json_response($previous ? 200 : 201, ['registration'=>find_registration($db,$session,$webinarId)]);
}

function remove_registration(PDO $db, array $session, int $webinarId): never
{
    $registration = find_registration($db,$session,$webinarId);
    if (!$registration) throw new ApiError(401, 'No registration is available in this browser.');
    $db->beginTransaction();
    try {
        $query = $db->prepare('DELETE FROM lead_webinars WHERE id=?');
        $query->execute([$registration['id']]);
        $db->exec('DELETE FROM leads WHERE NOT EXISTS (SELECT 1 FROM lead_webinars WHERE lead_id=leads.id)');
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    json_response(200, ['deleted'=>true]);
}
