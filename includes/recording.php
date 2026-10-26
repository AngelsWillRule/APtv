<?php
require_once __DIR__ . '/recording_storage.php';

function openapRecordingNfsMountpoint(): string
{
    return '/mnt/aptv-recordings';
}

function openapRecordingNfsCall(string $action, string $server = '', string $exportPath = ''): string
{
    $command = ['/usr/bin/sudo', '-n', '/usr/local/sbin/openap-iptv-nfs', $action];
    if ($action === 'mount') {
        $command[] = $server;
        $command[] = $exportPath;
    }
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('[REC_NFS_HELPER] Unable to start NFS helper.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        $detail = trim((string)preg_replace('/\s+/', ' ', $stderr));
        $detail = (string)preg_replace('/(password|passwd|secret|token)=\S+/i', '$1=[redacted]', $detail);
        if (strlen($detail) > 280) $detail = substr($detail, 0, 277).'...';
        throw new RuntimeException('[REC_NFS_'.$action.'] '.($detail !== '' ? $detail : 'NFS operation failed.'));
    }
    return trim($stdout);
}

function openapRecordingNfsState(): array
{
    try {
        $state = openapRecordingNfsCall('status');
        return ['mounted' => $state === 'mounted', 'mountpoint' => openapRecordingNfsMountpoint()];
    } catch (Throwable $error) {
        return ['mounted' => false, 'mountpoint' => openapRecordingNfsMountpoint(), 'error' => '[REC_NFS_STATUS] NFS status unavailable.'];
    }
}

function openapRecordingDb(): SQLite3
{
    $path = PHP_SAPI === 'cli' && defined('OPENAP_RECORDING_TEST_DB') ? OPENAP_RECORDING_TEST_DB : '/var/lib/openap-recording-state/recordings.db';
    $db = new SQLite3($path); $db->enableExceptions(true); $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec("CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY CHECK(id=1), destination TEXT NOT NULL)");
    foreach (['nfs_server' => 'TEXT NOT NULL DEFAULT ""', 'nfs_export' => 'TEXT NOT NULL DEFAULT ""'] as $column => $definition) {
        try { $db->exec('ALTER TABLE settings ADD COLUMN '.$column.' '.$definition); } catch (SQLite3Exception $error) {}
    }
    $db->exec("CREATE TABLE IF NOT EXISTS jobs (id INTEGER PRIMARY KEY, provider_id TEXT NOT NULL, channel_id TEXT NOT NULL, channel_name TEXT NOT NULL, title TEXT NOT NULL, start INTEGER NOT NULL, end INTEGER NOT NULL, destination TEXT NOT NULL, status TEXT NOT NULL, file TEXT NOT NULL DEFAULT '', error TEXT NOT NULL DEFAULT '', bytes INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL, actual_start INTEGER, finished INTEGER, UNIQUE(provider_id,channel_id,start,destination))");
    $schema=(string)($db->querySingle("SELECT sql FROM sqlite_master WHERE type='table' AND name='jobs'")?:'');
    if($schema!==''&&!str_contains($schema,'UNIQUE(provider_id,channel_id,start,destination)')){
        $db->exec('BEGIN IMMEDIATE');
        try{
            $db->exec("CREATE TABLE jobs_v2 (id INTEGER PRIMARY KEY, provider_id TEXT NOT NULL, channel_id TEXT NOT NULL, channel_name TEXT NOT NULL, title TEXT NOT NULL, start INTEGER NOT NULL, end INTEGER NOT NULL, destination TEXT NOT NULL, status TEXT NOT NULL, file TEXT NOT NULL DEFAULT '', error TEXT NOT NULL DEFAULT '', bytes INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL, actual_start INTEGER, finished INTEGER, UNIQUE(provider_id,channel_id,start,destination))");
            $db->exec('INSERT INTO jobs_v2 SELECT * FROM jobs');
            $db->exec('DROP TABLE jobs');$db->exec('ALTER TABLE jobs_v2 RENAME TO jobs');
            $db->exec('COMMIT');
        }catch(Throwable $error){$db->exec('ROLLBACK');throw $error;}
    }
    $db->exec('CREATE INDEX IF NOT EXISTS jobs_schedule ON jobs(status,start,end)');
    return $db;
}
function openapRecordingQuery(SQLite3 $db, string $sql, array $values=[]): SQLite3Result
{
    $stmt=$db->prepare($sql);
    foreach($values as $name=>$value) $stmt->bindValue(':'.$name,$value,is_int($value)?SQLITE3_INTEGER:SQLITE3_TEXT);
    return $stmt->execute();
}
function openapRecordingWritable(string $path): string
{
    $path=openapRecordingPath($path);
    $probe=$path.'/.aptv-write-check-'.bin2hex(random_bytes(12));$handle=@fopen($probe,'x');
    if(!$handle)throw new RuntimeException(_('APtv cannot write to this folder.'));
    try{$written=fwrite($handle,'APtv');}finally{fclose($handle);$removed=@unlink($probe);}
    if($written!==4||!$removed)throw new RuntimeException(_('The folder did not pass the write check.'));
    $free=@disk_free_space($path);
    if($free!==false&&$free<104857600)throw new RuntimeException(_('Less than 100 MiB is available. Choose another destination.'));
    return $path;
}
function openapRecordingPublicJobs(SQLite3 $db): array
{
    $result=$db->query("SELECT id,provider_id,channel_id,channel_name,title,start,end,destination,status,file,error,bytes,actual_start,finished FROM jobs WHERE status IN ('scheduled','starting','recording','reconnecting','stopping') OR created>".(time()-604800)." ORDER BY start DESC,id DESC LIMIT 500");
    $items=[];while($row=$result->fetchArray(SQLITE3_ASSOC)){if(is_string($row['file']??null)&&str_ends_with(strtolower($row['file']),'.mp4')){$tsPath=substr($row['file'],0,-4).'.ts';if(is_file($tsPath))$row['file']=$tsPath;}$items[]=$row;}return $items;
}
function openapRecordingCreate(SQLite3 $db,array $job): int
{
    $now=time();
    if($job['end']<=$now||$job['start']> $now+604800||$job['end']-$job['start']>86400||$job['end']<=$job['start'])throw new InvalidArgumentException(_('This programme cannot be scheduled.'));
    $job['destination']=openapRecordingWritable($job['destination']);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $existing=openapRecordingQuery($db,'SELECT id,status FROM jobs WHERE provider_id=:provider_id AND channel_id=:channel_id AND start=:start AND destination=:destination',['provider_id'=>$job['provider_id'],'channel_id'=>$job['channel_id'],'start'=>$job['start'],'destination'=>$job['destination']])->fetchArray(SQLITE3_ASSOC);
        if($existing&&!in_array($existing['status'],['cancelled','failed','missed','interrupted','stopped'],true)){$db->exec('COMMIT');return (int)$existing['id'];}
        // A provider is an independent capture source.  Keep the single-
        // recording limit within the same provider, but allow parallel jobs
        // when they use different provider profiles.
        $conflict=openapRecordingQuery($db,"SELECT id FROM jobs WHERE provider_id=:provider_id AND status IN ('scheduled','starting','recording','reconnecting','stopping') AND start<:end AND end>:start LIMIT 1",['provider_id'=>$job['provider_id'],'start'=>max($now,$job['start']),'end'=>$job['end']])->fetchArray(SQLITE3_ASSOC);
        if($conflict)throw new RuntimeException(_('Another recording overlaps this programme. Only one recording at a time is supported.'));
        if($existing) {
            // Keep a previous partial file on disk; a retry always gets a new filename.
            openapRecordingQuery($db,"UPDATE jobs SET end=:end,destination=:destination,status='scheduled',file='',error='',bytes=0,actual_start=NULL,finished=NULL,created=:created WHERE id=:id",['end'=>$job['end'],'destination'=>$job['destination'],'created'=>$now,'id'=>(int)$existing['id']]);$id=(int)$existing['id'];
        } else {
            $job['created']=$now;
            openapRecordingQuery($db,"INSERT INTO jobs(provider_id,channel_id,channel_name,title,start,end,destination,status,created) VALUES(:provider_id,:channel_id,:channel_name,:title,:start,:end,:destination,'scheduled',:created)",$job);$id=$db->lastInsertRowID();
        }
        $db->exec('COMMIT');return $id;
    } catch(Throwable $error){$db->exec('ROLLBACK');throw $error;}
}
function openapRecordingApi(): void
{
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if(!in_array($method,['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');echo json_encode(['ok'=>false,'message'=>_('Method not allowed.')]);return;}
    if($method==='POST'&&!\OpenAP\Tokens\CSRF::verify()){http_response_code(403);echo json_encode(['ok'=>false,'message'=>_('Invalid security token. Reload the page.')]);return;}
    try {
        $db=openapRecordingDb();
        if($method==='POST') {
            $action=$_POST['action']??'';
            if($action==='settings') {
                $type=(string)($_POST['type']??'local');
                if($type==='local') {
                    $path=openapRecordingWritable('/var/lib/openap-recordings');
                    openapRecordingQuery($db,'INSERT INTO settings(id,destination) VALUES(1,:path) ON CONFLICT(id) DO UPDATE SET destination=excluded.destination',['path'=>$path]);
                } elseif($type==='nfs') {
                    $server=trim((string)($_POST['server']??''));$exportPath=trim((string)($_POST['export']??''));
                    if($server===''||$exportPath==='')throw new InvalidArgumentException(_('Enter the NFS server and exported folder.'));
                    $currentDestination=(string)($db->querySingle('SELECT destination FROM settings WHERE id=1')?:'');
                    if($currentDestination==='')$currentDestination='/var/lib/openap-recordings';
                    openapRecordingQuery($db,'INSERT INTO settings(id,destination,nfs_server,nfs_export) VALUES(1,:path,:server,:export) ON CONFLICT(id) DO UPDATE SET destination=excluded.destination,nfs_server=excluded.nfs_server,nfs_export=excluded.nfs_export',['path'=>$currentDestination,'server'=>$server,'export'=>$exportPath]);
                } else throw new InvalidArgumentException(_('Invalid recording destination.'));
            } elseif($action==='nfs_mount') {
                $server=(string)($_POST['server']??'');$exportPath=(string)($_POST['export']??'');
                if($server===''||$exportPath==='')throw new InvalidArgumentException(_('Enter the NFS server and exported folder.'));
                openapRecordingNfsCall('mount',$server,$exportPath);
                $path=openapRecordingWritable(openapRecordingNfsMountpoint());
                openapRecordingQuery($db,'INSERT INTO settings(id,destination,nfs_server,nfs_export) VALUES(1,:path,:server,:export) ON CONFLICT(id) DO UPDATE SET destination=excluded.destination,nfs_server=excluded.nfs_server,nfs_export=excluded.nfs_export',['path'=>$path,'server'=>$server,'export'=>$exportPath]);
            } elseif($action==='nfs_unmount') {
                $active=$db->querySingle("SELECT COUNT(*) FROM jobs WHERE status IN ('scheduled','starting','recording','reconnecting','stopping') AND destination LIKE '/mnt/aptv-recordings%'");
                if((int)$active>0)throw new RuntimeException(_('Cannot unmount NFS while a recording is scheduled or active.'));
                openapRecordingNfsCall('unmount');
                openapRecordingQuery($db,"UPDATE settings SET destination='' WHERE id=1 AND destination LIKE '/mnt/aptv-recordings%'");
            } elseif($action==='record') {
                $destinationType=(string)($_POST['destination']??'local');
                if($destinationType==='local')$destination='/var/lib/openap-recordings';
                elseif($destinationType==='nfs') {
                    $settings=$db->querySingle('SELECT nfs_server,nfs_export FROM settings WHERE id=1',true)?:[];
                    if(empty($settings['nfs_server'])||empty($settings['nfs_export']))throw new RuntimeException(_('Configure NFS storage in Settings first.'));
                    if(!openapRecordingNfsState()['mounted'])throw new RuntimeException(_('Mount NFS storage before recording there.'));
                    $destination=openapRecordingNfsMountpoint();
                } else throw new InvalidArgumentException(_('Invalid recording destination.'));
                $id=(string)($_POST['channel_id']??'');$providerId=trim((string)($_POST['provider_id']??''));$start=filter_var($_POST['start']??null,FILTER_VALIDATE_INT);$end=filter_var($_POST['end']??null,FILTER_VALIDATE_INT);
                if(!preg_match('/^[0-9]{1,20}$/',$id)||!preg_match('/^[a-f0-9]{32}$/',$providerId)||!openapAptvProviderProfileById($providerId)||$start===false||$end===false)throw new InvalidArgumentException(_('Invalid programme or provider.'));
                // Resolve programme metadata from the server EPG, never trust browser titles or stream URLs.
                $previous=$_GET;$_GET=['item_id'=>$id,'provider_id'=>$providerId];ob_start();
                try{openapAptvUnifiedEpg();$epg=json_decode(ob_get_contents(),true);}finally{ob_end_clean();$_GET=$previous;}
                http_response_code(200);$channel=$epg['items'][0]??null;$program=null;
                foreach(($channel['programs']??[]) as $candidate)if((int)$candidate['start']===$start&&(int)$candidate['end']===$end){$program=$candidate;break;}
                if(!$program)throw new InvalidArgumentException(_('Programme no longer matches the EPG. Refresh the guide.'));
                openapRecordingCreate($db,['provider_id'=>$providerId,'channel_id'=>$id,'channel_name'=>(string)$channel['name'],'title'=>(string)$program['title'],'start'=>$start,'end'=>$end,'destination'=>$destination]);
            } elseif($action==='stop') {
                $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);
                if(!$id)throw new InvalidArgumentException(_('Invalid recording.'));
                openapRecordingQuery($db,"UPDATE jobs SET status=CASE WHEN status='scheduled' THEN 'cancelled' ELSE 'stopping' END WHERE id=:id AND status IN ('scheduled','starting','recording','reconnecting')",['id'=>$id]);
            } else throw new InvalidArgumentException(_('Invalid recording action.'));
        }
        $heartbeat=@filemtime('/var/lib/openap-recording-state/heartbeat');
        $collection=openapAptvProviderCollection();
        $settings=$db->querySingle('SELECT destination,nfs_server,nfs_export FROM settings WHERE id=1',true)?:[];
        echo json_encode(['ok'=>true,'destination'=>(string)($settings['destination']??''),'nfs_server'=>(string)($settings['nfs_server']??''),'nfs_export'=>(string)($settings['nfs_export']??''),'provider_id'=>(string)($collection['active_id']??''),'service_online'=>$heartbeat!==false&&$heartbeat>time()-15,'nfs'=>openapRecordingNfsState(),'items'=>openapRecordingPublicJobs($db)],JSON_INVALID_UTF8_SUBSTITUTE);
    }catch(InvalidArgumentException $error){http_response_code(422);echo json_encode(['ok'=>false,'message'=>$error->getMessage()]);}
    catch(Throwable $error){http_response_code(409);echo json_encode(['ok'=>false,'message'=>$error instanceof RuntimeException&&!$error instanceof SQLite3Exception?$error->getMessage():_('Recording service unavailable. Please try again.')]);}
}
