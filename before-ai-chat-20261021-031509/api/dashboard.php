<?php
declare(strict_types=1);

function dashboard_scope(string $view,array $filters): array
{
    $from = match($view) {
        'video'=> 'playback_sessions p JOIN lead_webinars r ON r.id=p.registration_id JOIN leads l ON l.id=r.lead_id JOIN webinars w ON w.id=r.webinar_id',
        'chat'=> 'chat_conversations c JOIN webinars w ON w.id=c.webinar_id LEFT JOIN lead_webinars r ON r.id=c.registration_id LEFT JOIN leads l ON l.id=r.lead_id',
        default=> 'lead_webinars r JOIN leads l ON l.id=r.lead_id JOIN webinars w ON w.id=r.webinar_id',
    };
    $date = match($view) {'video'=>'p.started_at','chat'=>'c.created_at',default=>'r.registered_at'};
    $where=['1=1']; $values=[];
    $webinarId = filter_var($filters['webinarId'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    if ($webinarId===false) throw new ApiError(422,'Invalid webinar filter.');
    if ($webinarId) { $where[]='w.id=?'; $values[]=$webinarId; }
    $parsed=[];
    foreach (['from','to'] as $key) {
        $raw=$filters[$key] ?? '';
        if ($raw==='') continue;
        if (!is_string($raw)) throw new ApiError(422,'Invalid date filter.');
        $dateValue=DateTimeImmutable::createFromFormat('!Y-m-d',$raw,new DateTimeZone('Asia/Kolkata'));
        if (!$dateValue || $dateValue->format('Y-m-d')!==$raw) throw new ApiError(422,'Use valid dates for the report period.');
        $parsed[$key]=$dateValue;
        $where[]=$date.($key==='from' ? '>=?' : '<?');
        $values[]=($key==='to' ? $dateValue->modify('+1 day') : $dateValue)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    if (isset($parsed['from'],$parsed['to']) && $parsed['from']>$parsed['to']) throw new ApiError(422,'The start date must be before the end date.');
    $search=$filters['search'] ?? '';
    if (!is_string($search) || mb_strlen($search)>120) throw new ApiError(422,'Use a search of at most 120 characters.');
    if (trim($search)!=='') {
        $like='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],trim($search)).'%';
        $where[]="(CONCAT(l.first_name,' ',l.last_name) LIKE ? OR l.email LIKE ? OR l.company LIKE ?)";
        array_push($values,$like,$like,$like);
    }
    $status="CASE WHEN p.status IN ('playing','created') AND p.updated_at<UTC_TIMESTAMP(6)-INTERVAL 45 SECOND THEN 'abandoned' ELSE p.status END";
    if ($view==='video' && ($filters['status'] ?? '')!=='') {
        if (!in_array($filters['status'],['created','playing','paused','completed','ended','abandoned'],true)) throw new ApiError(422,'Invalid video status.');
        $where[]="($status)=?"; $values[]=$filters['status'];
    }
    return ['from'=>$from,'where'=>implode(' AND ',$where),'values'=>$values,'status'=>$status];
}
function dashboard_query(PDO $db,string $select,array $scope,string $tail=''): array
{
    $query=$db->prepare("SELECT $select FROM {$scope['from']} WHERE {$scope['where']} $tail");
    $query->execute($scope['values']); return $query->fetchAll();
}
function dashboard_rows(PDO $db,string $view,array $scope,int $page=1,int $limit=20,bool $all=false): array
{
    $columns=match($view) {
        'video'=>"p.id,CONCAT(l.first_name,' ',l.last_name) AS name,l.email,w.title AS webinar,p.started_at,p.updated_at,p.last_position_seconds,p.watched_seconds,p.completion_percent,p.duration_seconds,({$scope['status']}) AS status,(SELECT COUNT(*) FROM playback_events e WHERE e.playback_session_id=p.id) AS event_count",
        'chat'=>"c.id,COALESCE(CONCAT(l.first_name,' ',l.last_name),'Anonymous') AS name,l.email,w.title AS webinar,c.created_at,c.updated_at,(SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id=c.id AND m.role='user') AS question_count,(SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id=c.id AND m.role='assistant') AS response_count",
        default=>"r.id,CONCAT(l.first_name,' ',l.last_name) AS name,l.email,l.phone,l.company,l.job_title,l.country,w.title AS webinar,r.registered_at,r.marketing_opt_in,r.activity_consent,(SELECT COUNT(*) FROM playback_sessions p WHERE p.registration_id=r.id) AS video_sessions,(SELECT COUNT(*) FROM chat_conversations c WHERE c.registration_id=r.id) AS conversations",
    };
    $order=match($view) {'video'=>'p.started_at DESC,p.id','chat'=>'c.created_at DESC,c.id',default=>'r.registered_at DESC,r.id DESC'};
    $total=(int) dashboard_query($db,'COUNT(*) AS n',$scope)[0]['n'];
    if ($all && $total>50000) throw new ApiError(422,'Narrow your filters to export no more than 50,000 records.');
    $page=max(1,min($page,max(1,(int) ceil($total/$limit))));
    $tail="ORDER BY $order".($all ? '' : ' LIMIT '.$limit.' OFFSET '.(($page-1)*$limit));
    return ['rows'=>dashboard_query($db,$columns,$scope,$tail),'total'=>$total,'page'=>$page,'pageSize'=>$limit];
}
function dashboard_api(PDO $db,string $resource,string $method): never
{
    $admin=require_admin($db);
    if ($method!=='POST') throw new ApiError(405,'Method not allowed.');
    require_write_access($admin);
    $data=read_json();
    if ($resource==='dashboard_detail') {
        $id=$data['id'] ?? '';
        if (($data['view'] ?? '')==='chat') {
            if (!valid_uuid($id)) throw new ApiError(422,'Invalid conversation ID.');
            $query=$db->prepare('SELECT c.id,w.title AS webinar,CONCAT(l.first_name,\' \',l.last_name) AS name,l.email FROM chat_conversations c JOIN webinars w ON w.id=c.webinar_id LEFT JOIN lead_webinars r ON r.id=c.registration_id LEFT JOIN leads l ON l.id=r.lead_id WHERE c.id=?');
            $query->execute([$id]); $record=$query->fetch();
            if (!$record) throw new ApiError(404,'Conversation not found.');
            json_response(200,['record'=>$record,'messages'=>conversation_messages($db,$id)]);
        }
        if (!valid_uuid($id)) throw new ApiError(422,'Invalid viewing session ID.');
        $query=$db->prepare('SELECT p.*,w.title AS webinar,CONCAT(l.first_name,\' \',l.last_name) AS name,l.email FROM playback_sessions p JOIN lead_webinars r ON r.id=p.registration_id JOIN leads l ON l.id=r.lead_id JOIN webinars w ON w.id=r.webinar_id WHERE p.id=?');
        $query->execute([$id]); $record=$query->fetch();
        if (!$record) throw new ApiError(404,'Viewing session not found.');
        $page=filter_var($data['page'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]) ?: 1;
        $query=$db->prepare('SELECT COUNT(*) FROM playback_events WHERE playback_session_id=?'); $query->execute([$id]); $total=(int) $query->fetchColumn();
        $query=$db->prepare('SELECT event_type,position_seconds,previous_position_seconds,new_position_seconds,watched_delta_seconds,occurred_at,received_at FROM playback_events WHERE playback_session_id=? ORDER BY occurred_at,id LIMIT 100 OFFSET '.(($page-1)*100));
        $query->execute([$id]); json_response(200,['record'=>$record,'events'=>$query->fetchAll(),'page'=>$page,'total'=>$total]);
    }
    $view=$data['view'] ?? 'users';
    if (!in_array($view,['users','video','chat'],true)) throw new ApiError(422,'Invalid dashboard view.');
    $filters=$data['filters'] ?? [];
    if (!is_array($filters)) throw new ApiError(422,'Invalid filters.');
    $scopes=[];
    foreach (['users','video','chat'] as $kind) $scopes[$kind]=dashboard_scope($kind,$filters);
    $page=filter_var($data['page'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]) ?: 1;
    $result=dashboard_rows($db,$view,$scopes[$view],$page,20,$resource==='dashboard_export');
    if ($resource==='dashboard_export') {
        header('Content-Type: text/csv; charset=utf-8'); header('Cache-Control: no-store');
        header('Content-Disposition: attachment; filename="webinar-'.$view.'-'.gmdate('Y-m-d').'.csv"');
        $out=fopen('php://output','wb'); fwrite($out,"\xEF\xBB\xBF");
        if ($result['rows']) {
            fputcsv($out,array_keys($result['rows'][0]));
            foreach ($result['rows'] as $row) {
                foreach ($row as &$value) if (is_string($value) && preg_match('/^[\s]*[=+@\-]/u',$value)) $value="'".$value;
                unset($value); fputcsv($out,$row);
            }
        } else fputcsv($out,['No records match the selected filters.']);
        fclose($out); exit;
    }
    $users=dashboard_query($db,'COUNT(*) AS registrations,COUNT(DISTINCT l.id) AS users,SUM(r.activity_consent) AS consented',$scopes['users'])[0];
    $video=dashboard_query($db,"COUNT(*) AS sessions,COALESCE(SUM(p.watched_seconds),0) AS watched_seconds,AVG(p.completion_percent) AS average_progress,COALESCE(SUM(p.status='completed'),0) AS reached_end",$scopes['video'])[0];
    $chat=dashboard_query($db,"COUNT(*) AS conversations,COALESCE(SUM((SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id=c.id AND m.role='user')),0) AS questions",$scopes['chat'])[0];
    $metric=match($view) {'video'=>'SUM(p.watched_seconds)','chat'=>"SUM((SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id=c.id AND m.role='user'))",default=>'COUNT(*)'};
    $breakdown=dashboard_query($db,"w.id,w.title,$metric AS value",$scopes[$view],'GROUP BY w.id,w.title ORDER BY w.id');
    $started=$db->query("SELECT applied_at FROM schema_migrations WHERE version='002_activity_dashboard'")->fetchColumn();
    $ready=(int) $db->query('SELECT COUNT(DISTINCT webinar_id) FROM webinar_transcripts')->fetchColumn();
    json_response(200,$result+[
        'summary'=>['registrations'=>(int) $users['registrations'],'users'=>(int) $users['users'],'activityConsented'=>(int) ($users['consented'] ?? 0),
            'sessions'=>(int) $video['sessions'],'watchedSeconds'=>(float) $video['watched_seconds'],'averageProgress'=>$video['average_progress']===null ? null : (float) $video['average_progress'],
            'reachedEnd'=>(int) $video['reached_end'],'conversations'=>(int) $chat['conversations'],'questions'=>(int) $chat['questions']],
        'breakdown'=>$breakdown,'webinars'=>$db->query('SELECT id,title FROM webinars ORDER BY id')->fetchAll(),
        'meta'=>['fetchedAt'=>gmdate('c'),'timezone'=>'Asia/Kolkata','trackingStartedAt'=>$started ?: null,'transcriptWebinars'=>$ready,
            'sources'=>['leads','lead_webinars','playback_sessions','playback_events','chat_conversations','chat_messages']],
    ]);
}
