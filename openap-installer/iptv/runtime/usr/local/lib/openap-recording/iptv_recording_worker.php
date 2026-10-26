<?php
require '/var/www/html/includes/recording.php';
umask(0007);
$lock=fopen('/var/lib/openap-recording-state/worker.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(1);
$db=openapRecordingDb();
// Files already written are retained. Resume interrupted programmes in a new part.
$db->exec("UPDATE jobs SET status='scheduled',error='Resumed after service restart; earlier part retained.' WHERE status IN ('starting','recording','reconnecting') AND end>".time());
$db->exec("UPDATE jobs SET status='interrupted',error='Service stopped before the recording completed.' WHERE status IN ('starting','recording','reconnecting')");
$db->exec("UPDATE jobs SET status='stopped' WHERE status='stopping'");
$children=[];
$maximumConcurrent=16;
$reapChildren=function()use(&$children,$db):void{
    foreach(array_keys($children)as$jobId){$entry=$children[$jobId];$child=$entry['process']??null;if(!is_resource($child)){unset($children[$jobId]);continue;}$state=proc_get_status($child);if($state['running'])continue;proc_close($child);$row=openapRecordingQuery($db,'SELECT status FROM jobs WHERE id=:id',['id'=>$jobId])->fetchArray(SQLITE3_ASSOC);$status=(string)($row['status']??'');if(in_array($status,['starting','recording','reconnecting','stopping'],true)){openapRecordingQuery($db,"UPDATE jobs SET error=CASE WHEN status='stopping' THEN '' ELSE 'Recording worker stopped unexpectedly.' END,status=CASE WHEN status='stopping' THEN 'stopped' ELSE 'failed' END,finished=:now WHERE id=:id",['now'=>time(),'id'=>$jobId]);}unset($children[$jobId]);}
};
while(true){
    touch('/var/lib/openap-recording-state/heartbeat');
    $reapChildren();
    $now=time();
    openapRecordingQuery($db,"UPDATE jobs SET status='missed',error='The device was unavailable during the programme.',finished=:now WHERE status='scheduled' AND end<=:now",['now'=>$now]);
    if(count($children)<$maximumConcurrent){
        $toLaunch=[];$activeProviders=[];foreach($children as$entry){$activeProviders[(string)($entry['provider_id']??'')]=true;}
        $db->exec('BEGIN IMMEDIATE');
        try{
            $query=openapRecordingQuery($db,"SELECT id,provider_id FROM jobs WHERE status='scheduled' AND start<=:now AND end>:now ORDER BY start,id LIMIT ".(int)$maximumConcurrent,['now'=>$now]);
            while($job=$query->fetchArray(SQLITE3_ASSOC)){
                $jobId=(int)($job['id']??0);$providerId=(string)($job['provider_id']??'');
                if($jobId<1||$providerId===''||isset($activeProviders[$providerId]))continue;
                openapRecordingQuery($db,"UPDATE jobs SET status='starting' WHERE id=:id AND status='scheduled'",['id'=>$jobId]);
                $toLaunch[]=['id'=>$jobId,'provider_id'=>$providerId];$activeProviders[$providerId]=true;
                if(count($children)+count($toLaunch)>=$maximumConcurrent)break;
            }
            $db->exec('COMMIT');
        }catch(Throwable $error){$db->exec('ROLLBACK');throw $error;}
        foreach($toLaunch as$job){
            $child=proc_open(['/usr/bin/php','/usr/local/lib/openap-recording/iptv_recording_capture.php',(string)$job['id']],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
            if(is_resource($child))$children[(int)$job['id']]=['process'=>$child,'provider_id'=>(string)$job['provider_id']];
            else openapRecordingQuery($db,"UPDATE jobs SET status='failed',error='Could not launch recording worker.',finished=:now WHERE id=:id AND status='starting'",['now'=>time(),'id'=>(int)$job['id']]);
        }
    }
    sleep(1);
}
