<?php
if(PHP_SAPI!=='cli'||(count($argv)!==3&&count($argv)!==4))exit(64);
require '/var/www/html/includes/iptv_api.php';
require '/var/www/html/includes/live_relay.php';
umask(0007);$provider=(string)$argv[1];$channel=(string)$argv[2];
$requestedProfile=(string)($argv[3]??'');
$dir=openapLiveRelayKey($provider,$channel).'-hls';
if(!is_dir($dir))exit(65);
$stateRoot='/var/lib/openap-recording-state';
foreach(glob($stateRoot.'/live-*-hls',GLOB_ONLYDIR)?:[] as $stale){
    if($stale===$dir||!is_file($stale.'/heartbeat')||time()-(int)@filemtime($stale.'/heartbeat')<=600)continue;
    $staleLock=@fopen($stale.'/lock','c');
    if(!$staleLock||!@flock($staleLock,LOCK_EX|LOCK_NB)){if(is_resource($staleLock))fclose($staleLock);continue;}
    foreach(glob($stale.'/*')?:[] as $file)if(is_file($file)||is_link($file))@unlink($file);
    @rmdir($stale);@flock($staleLock,LOCK_UN);fclose($staleLock);
}
$lock=fopen($dir.'/lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
$pidFile=$dir.'/pid';@file_put_contents($pidFile,(string)getmypid(),LOCK_EX);
foreach(glob($dir.'/*.ts')?:[] as $file)@unlink($file);@unlink($dir.'/index.m3u8');
$command=['/usr/bin/ffmpeg','-hide_banner','-loglevel','warning','-nostdin','-fflags','+genpts+discardcorrupt','-err_detect','ignore_err','-i','pipe:0','-map','0:v:0?','-map','0:a:0?'];
$profile=$requestedProfile==='normalized'?'normalized':openapAptvLiveCompatibilityProfile($channel,$provider);
@file_put_contents($dir.'/profile',$profile,LOCK_EX);
if($profile==='normalized'){
    // Normalize provider profiles whose live transport is not reliably
    // consumable by browser HLS after a stream-format change.
    // Do not force a frame rate: preserve the provider's actual 25/30/50 fps
    // while still normalizing codec, timestamps and keyframe structure.
    $command=array_merge($command,['-vf','scale=trunc(iw/2)*2:trunc(ih/2)*2,setsar=1','-c:v','libx264','-preset','ultrafast','-tune','zerolatency','-pix_fmt','yuv420p','-profile:v','main','-g','60','-keyint_min','60','-sc_threshold','0','-bf','0','-c:a','aac','-profile:a','aac_low','-ar','48000','-ac','2','-b:a','128k']);
}else{
    $command=array_merge($command,['-c','copy']);
}
$hlsListSize=30;
$hlsDeleteThreshold=10;
$command=array_merge($command,['-max_muxing_queue_size','2048','-avoid_negative_ts','make_zero','-f','hls','-hls_time','2','-hls_list_size',(string)$hlsListSize,'-hls_delete_threshold',(string)$hlsDeleteThreshold,'-hls_flags','delete_segments+independent_segments+temp_file+omit_endlist','-hls_segment_filename',$dir.'/seg-%09d.ts',$dir.'/index.m3u8']);
$ff=proc_open($command,[0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file',$dir.'/ffmpeg.log','a']],$pipes);
if(!is_resource($ff))exit(66);
stream_set_blocking($pipes[0],false);
$stop=static function()use($dir,$ff):bool{clearstatcache(true,$dir.'/heartbeat');return time()-(int)@filemtime($dir.'/heartbeat')>30||!proc_get_status($ff)['running'];};
try{openapLiveRelayConsume($provider,$channel,static function(string $chunk)use($pipes,$stop):int{
    $offset=0;$length=strlen($chunk);$until=microtime(true)+10;
    while($offset<$length){if($stop()||microtime(true)>$until)return 0;$n=@fwrite($pipes[0],substr($chunk,$offset));if($n===false)return 0;if(!$n){usleep(10000);continue;}$offset+=$n;}return $length;
},$stop);}catch(Throwable $error){}finally{
    fclose($pipes[0]);proc_terminate($ff);proc_close($ff);
    @unlink($pidFile);
    @unlink($dir.'/index.m3u8');
}
