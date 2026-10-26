<?php
// Stream credentials remain in PHP memory; FFmpeg receives media bytes through a pipe.
function openapRecordingCapture(SQLite3 $db, array $job, array $profile): void
{
    $id=(int)$job['id'];$process=null;$pipes=[];$curl=null;$path='';$failed='';$reason='';$received=0;$lastCheck=0;$recording=false;$written=0;$reconnecting=false;$pipeFailed=false;$retryCount=0;$diagnostic='';$interruptions=0;
    try {
        $directory=$job['destination']==='/mnt/aptv-recordings'?$job['destination'].'/APtv Recordings':$job['destination'];
        if($job['destination']==='/mnt/aptv-recordings'&&!is_dir($directory)&&!@mkdir($directory,0770)&&!is_dir($directory))throw new RuntimeException('[REC_FOLDER] Impossibile creare la cartella APtv Recordings.');
        $directory=openapRecordingWritable($directory);
        $segment=static function(string $value):string {
            return trim(preg_replace('/[^\pL\pN _.-]+/u','_',mb_substr($value,0,80)),' .')?:'Senza nome';
        };
        foreach([$job['channel_name'],$job['title']] as $name){
            $next=$directory.'/'.$segment($name);
            if(is_link($next))throw new RuntimeException('[REC_FOLDER] La cartella non deve essere un collegamento simbolico.');
            if(!is_dir($next)&&!@mkdir($next,0770)&&!is_dir($next))throw new RuntimeException('[REC_FOLDER] Impossibile creare la cartella canale/programma.');
            $directory=openapRecordingWritable($next);
        }
        $path=$directory.'/'.gmdate('Y-m-d_H-i-s').'_UTC-'.$id.'-'.bin2hex(random_bytes(4)).'.ts';
        $reserve=@fopen($path,'x');if(!$reserve)throw new RuntimeException('[REC_FILE] Impossibile creare il file di registrazione.');fclose($reserve);
        openapRecordingQuery($db,'UPDATE jobs SET file=:file,bytes=0 WHERE id=:id',['file'=>$path,'id'=>$id]);
        $process=proc_open(['/usr/bin/ffmpeg','-hide_banner','-loglevel','error','-nostdin','-fflags','+genpts','-i','pipe:0','-map','0:v:0?','-map','0:a?','-c','copy','-f','mpegts','-y',$path],[0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('[REC_ENGINE_START] Impossibile avviare FFmpeg.');
        stream_set_blocking($pipes[0],false);
        require_once '/var/www/html/includes/live_relay.php';
        $check=function() use($db,$job,$id,$path,&$lastCheck,&$reason,&$recording,&$written,&$reconnecting): bool {
            $now=time();if($now===$lastCheck)return $reason!=='';$lastCheck=$now;
            $state=openapRecordingQuery($db,'SELECT status FROM jobs WHERE id=:id',['id'=>$id])->fetchArray(SQLITE3_ASSOC);
            if(($state['status']??'')==='stopping')$reason='stopped';
            elseif($now>=(int)$job['end'])$reason='completed';
            clearstatcache(true,$path);$written=(int)@filesize($path);
            if(!$recording&&$written>0&&!$reconnecting){$recording=true;openapRecordingQuery($db,"UPDATE jobs SET status='recording',actual_start=COALESCE(actual_start,:now),error='' WHERE id=:id AND status IN ('starting','reconnecting')",['now'=>$now,'id'=>$id]);}
            openapRecordingQuery($db,'UPDATE jobs SET bytes=:bytes WHERE id=:id',['bytes'=>$written,'id'=>$id]);
            $free=@disk_free_space(dirname($path));
            if($free!==false&&$free<52428800)$reason='disk_full';
            return $reason!=='';
        };
        for($attempt=0;;$attempt++) {
            $connectionStarted=microtime(true);$bytesBefore=$received;
            try {
                openapLiveRelayConsume((string)$job['provider_id'],(string)$job['channel_id'],
                    static function(string $data)use(&$received,$pipes,$check,&$pipeFailed,&$reconnecting):int{
                        $length=strlen($data);$offset=0;$deadline=microtime(true)+15;
                        while($offset<$length){
                            if($check())return 0;
                            if(microtime(true)>$deadline){$pipeFailed=true;return 0;}
                            $count=@fwrite($pipes[0],substr($data,$offset));
                            if($count===false){$pipeFailed=true;return 0;}
                            if($count===0){usleep(10000);continue;}$offset+=$count;
                        }
                        $received+=$length;$reconnecting=false;return $length;
                    },$check);
            }catch(RuntimeException $error){$diagnostic=$error->getMessage();}
            if($reason!=='')break;
            if($pipeFailed){$failed='[REC_ENGINE_PIPE] Il motore di registrazione non accetta dati.';break;}
            if(microtime(true)-$connectionStarted>=10&&$received-$bytesBefore>=65536)$attempt=0;
            if($attempt>=3){$failed='[REC_RETRY_LIMIT] Tre riconnessioni fallite. File parziale conservato. '.$diagnostic;break;}
            $interruptions++;$retryCount=$attempt+1;$reconnecting=true;$recording=false;
            openapRecordingQuery($db,"UPDATE jobs SET status='reconnecting',error=:message WHERE id=:id AND status IN ('starting','recording','reconnecting')",['message'=>'[REC_RECONNECT] Tentativo '.$retryCount.'/3. '.$diagnostic,'id'=>$id]);
            $until=microtime(true)+[2,5,10][$attempt];
            while(microtime(true)<$until){if($check())break;usleep(100000);}
            if($reason!=='')break;
        }
        if($reason==='disk_full')$failed='Recording stopped: storage space is low.';
        elseif($reason==='completed'&&$reconnecting)$failed='Stream did not recover before the programme ended ('.$diagnostic.'). Partial recording retained.';
    } catch(Throwable $error) {$failed=$error instanceof RuntimeException&&!$error instanceof SQLite3Exception?$error->getMessage():'[REC_INTERNAL] Errore interno nella preparazione o nel salvataggio.';}
    finally {
        if(is_resource($pipes[0]??null))fclose($pipes[0]);
        $exit=-1;
        if(is_resource($process)) {
            $deadline=microtime(true)+5;
            do{$state=proc_get_status($process);if(!$state['running']){$exit=$state['exitcode'];break;}usleep(100000);}while(microtime(true)<$deadline);
            if($state['running']){proc_terminate($process,15);usleep(200000);$state=proc_get_status($process);if($state['running'])proc_terminate($process,9);}
            proc_close($process);
        }
        if($curl)curl_close($curl);
        if(isset($url))sodium_memzero($url);
        clearstatcache();$written=$path!==''?(int)@filesize($path):0;$playbackPath=$path;$playbackBytes=$written;
        if($written===0){if($path!=='')@unlink($path);$failed=$reason==='stopped'?'':($failed?:'No playable media was received.');}
        if($exit!==0&&$failed===''&& !($reason==='stopped'&&$written===0))$failed='[REC_ENGINE_EXIT] FFmpeg terminato con codice '.$exit.'. File parziale eventualmente disponibile.';
        $final=$failed!==''?'failed':($reason==='stopped'?'stopped':'completed');
        if($failed===''&&$interruptions>0)$failed='[REC_GAPS] Registrazione terminata con '.$interruptions.' interruzioni del flusso; possono mancare parti del programma.';
        if($path!==''&&$written>0){
            $metadata=['recording_id'=>$id,'channel'=>$job['channel_name'],'programme'=>$job['title'],'scheduled_start'=>gmdate('c',(int)$job['start']),'scheduled_end'=>gmdate('c',(int)$job['end']),'finished'=>gmdate('c'),'status'=>$final,'bytes'=>$written,'error'=>$failed,'container'=>'mpegts','playback_container'=>'mpegts','interruptions'=>$interruptions];
            $actual=$db->querySingle('SELECT actual_start FROM jobs WHERE id='.$id);
            $metadata['actual_start']=$actual?gmdate('c',(int)$actual):null;
            if(@file_put_contents($path.'.json',json_encode($metadata,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),LOCK_EX)===false)$failed=trim($failed.' [REC_METADATA] Impossibile salvare i metadati JSON.');
        }
        openapRecordingQuery($db,'UPDATE jobs SET file=:file,status=:status,error=:error,bytes=:bytes,finished=:now WHERE id=:id',['file'=>$playbackPath,'status'=>$final,'error'=>$failed,'bytes'=>$playbackBytes,'now'=>time(),'id'=>$id]);
    }
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    require '/var/www/html/includes/recording.php';require '/var/www/html/includes/iptv_api.php';
    $db=openapRecordingDb();$id=(int)($argv[1]??0);
    $job=openapRecordingQuery($db,"SELECT * FROM jobs WHERE id=:id AND status='starting'",['id'=>$id])->fetchArray(SQLITE3_ASSOC);
    if(!$job)exit(1);
    $collection=openapAptvProviderCollection();$profile=null;
    foreach($collection['profiles']??[] as $candidate)if((string)($candidate['id']??'')===$job['provider_id']){$profile=$candidate;break;}
    if(!$profile){openapRecordingQuery($db,"UPDATE jobs SET status='failed',error='The scheduled provider is no longer available.',finished=:now WHERE id=:id",['now'=>time(),'id'=>$id]);exit(1);}
    openapRecordingCapture($db,$job,$profile);
}
