<?php
declare(strict_types=1);

function admin_session(PDO $db): ?array
{
    $token = $_COOKIE['webinar_admin_session'] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/D',$token)) return null;
    $query = $db->prepare('SELECT s.*,u.name,u.email FROM admin_sessions s JOIN admin_users u ON u.id=s.admin_id WHERE token_hash=? AND expires_at>UTC_TIMESTAMP(6) AND u.is_active=1');
    $query->execute([hash('sha256',$token)]);
    return $query->fetch() ?: null;
}
function require_admin(PDO $db): array
{
    $session = admin_session($db);
    if (!$session) throw new ApiError(401,'Sign in to view the dashboard.');
    return $session;
}
function admin_auth(PDO $db,array $config,string $resource,string $method): never
{
    $session = admin_session($db);
    $setup = !(int) $db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    if ($resource === 'admin_status' && $method === 'GET') {
        $visitor = $session ?: visitor_session($db,$config,true);
        json_response(200,['authenticated'=>(bool) $session,'setupRequired'=>$setup,'csrfToken'=>$visitor['csrf_token'],
            'user'=>$session ? ['name'=>$session['name'],'email'=>$session['email']] : null]);
    }
    if ($method !== 'POST') throw new ApiError(405,'Method not allowed.');
    if ($resource === 'admin_logout') {
        if ($session) {
            require_write_access($session);
            $query = $db->prepare('DELETE FROM admin_sessions WHERE token_hash=?'); $query->execute([$session['token_hash']]);
        }
        setcookie('webinar_admin_session','',['expires'=>time()-3600,'path'=>app_base().'/','httponly'=>true,'samesite'=>'Lax','secure'=>(bool) $config['cookie_secure']]);
        json_response(200,['loggedOut'=>true]);
    }
    require_write_access(visitor_session($db,$config));
    rate_limit($db,'admin_auth',10);
    $data = read_json();
    $email = is_string($data['email'] ?? null) ? strtolower(trim($data['email'])) : '';
    $password = is_string($data['password'] ?? null) ? $data['password'] : '';
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254 || strlen($password)>72)
        throw new ApiError(422,'Enter a valid email and a password of at most 72 characters.');
    if ($resource === 'admin_setup') {
        if (!in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true)) throw new ApiError(403,'First-time setup is available only on this computer.');
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        if (mb_strlen($name)<1 || mb_strlen($name)>120 || strlen($password)<12) throw new ApiError(422,'Enter your name and a password with 12–72 characters.');
        $lock = $db->query("SELECT GET_LOCK('webinar_admin_setup',5)")->fetchColumn();
        if (!$lock) throw new ApiError(409,'Setup is already in progress. Try signing in.');
        try {
            if ((int) $db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn()) throw new ApiError(409,'An admin account already exists. Sign in instead.');
            $query = $db->prepare('INSERT INTO admin_users(name,email,password_hash) VALUES(?,?,?)');
            $query->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
        } finally { $db->query("SELECT RELEASE_LOCK('webinar_admin_setup')"); }
    } elseif ($resource !== 'admin_login') throw new ApiError(404,'Authentication action not found.');
    $query = $db->prepare('SELECT * FROM admin_users WHERE email=? AND is_active=1'); $query->execute([$email]); $admin = $query->fetch();
    // Verify a dummy hash for unknown accounts to avoid a trivial timing distinction.
    $valid = password_verify($password,$admin['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
    if (!$admin || !$valid) throw new ApiError(401,'Email or password is incorrect.');
    $token = bin2hex(random_bytes(32)); $csrf = bin2hex(random_bytes(32));
    $query = $db->prepare('INSERT INTO admin_sessions(token_hash,admin_id,csrf_token,expires_at) VALUES(?,?,?,?)');
    $query->execute([hash('sha256',$token),$admin['id'],$csrf,gmdate('Y-m-d H:i:s',time()+28800)]);
    $query = $db->prepare('UPDATE admin_users SET last_login_at=UTC_TIMESTAMP(6) WHERE id=?'); $query->execute([$admin['id']]);
    setcookie('webinar_admin_session',$token,['expires'=>time()+28800,'path'=>app_base().'/','httponly'=>true,'samesite'=>'Lax','secure'=>(bool) $config['cookie_secure']]);
    json_response(200,['authenticated'=>true,'csrfToken'=>$csrf,'user'=>['name'=>$admin['name'],'email'=>$admin['email']]]);
}
