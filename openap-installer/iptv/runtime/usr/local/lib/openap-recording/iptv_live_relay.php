<?php
if(PHP_SAPI!=='cli'||count($argv)!==3)exit(64);
require '/var/www/html/includes/iptv_api.php';
require '/var/www/html/includes/live_relay.php';
umask(0007);
$provider=(string)$argv[1];$channel=(string)$argv[2];
if(!preg_match('/^[0-9]{1,20}$/',$channel))exit(64);
$base=openapLiveRelayKey($provider,$channel);
$lock=fopen($base.'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
$profile=null;foreach(openapAptvProviderCollection()['profiles']??[] as $p)if((string)$p['id']===$provider){$profile=$p;break;}
if(!$profile)exit(65);
@unlink($base.'.sock');
$server=stream_socket_server('unix://'.$base.'.sock',$errno,$error);
if(!$server)exit(66);
chmod($base.'.sock',0660);stream_set_blocking($server,false);
$clients=[];$buffers=[];$slowClients=0;$peakQueue=0;$idle=microtime(true);$curl=null;$multi=null;
$service=function()use($server,&$clients,&$buffers,&$idle,&$slowClients,&$peakQueue,$base):bool{
    while($client=@stream_socket_accept($server,0)){stream_set_blocking($client,false);$key=(int)$client;$clients[$key]=$client;$buffers[$key]='';}
    foreach($clients as $key=>$client){
        $queued=strlen($buffers[$key]);$peakQueue=max($peakQueue,$queued);
        // The provider can send tens of seconds at once. A real-time transcoder
        // drains this burst gradually; keep a bounded 32 MiB queue per reader.
        if($queued>33554432){$slowClients++;file_put_contents($base.'.json',json_encode(['http'=>0,'transport'=>0,'slow_clients'=>$slowClients,'peak_queue_bytes'=>$peakQueue]));}
        if(feof($client)||$queued>33554432){fclose($client);unset($clients[$key],$buffers[$key]);continue;}
        if($buffers[$key]!==''){$n=@fwrite($client,$buffers[$key]);if($n===false){fclose($client);unset($clients[$key],$buffers[$key]);}elseif($n>0)$buffers[$key]=substr($buffers[$key],$n);}
    }
    if($clients)$idle=microtime(true);
    return microtime(true)-$idle<8;
};
try{
    // Wait for the first local subscriber before opening the provider.
    while(!$clients&&$service())usleep(20000);
    if(!$clients)exit;
    $url=rtrim($profile['url'],'/').'/live/'.rawurlencode($profile['username']).'/'.rawurlencode($profile['password']).'/'.$channel.'.ts';
    $curl=curl_init($url);$multi=curl_multi_init();
    curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>4,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_LOW_SPEED_LIMIT=>1,CURLOPT_LOW_SPEED_TIME=>20,CURLOPT_FAILONERROR=>true,CURLOPT_USERAGENT=>'APtv-Live/0.1',CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$clients,&$buffers,$service):int{
        if(!$service())return 0;
        foreach($clients as $key=>$client)$buffers[$key].=$chunk;
        return strlen($chunk);
    }]);
    file_put_contents($base.'.json',json_encode(['http'=>0,'transport'=>0]));
    curl_multi_add_handle($multi,$curl);
    do{
        curl_multi_exec($multi,$running);
        if(!$service())break;
        if($running){curl_multi_select($multi,0.05);usleep(1000);}
    }while($running);
    $info=curl_multi_info_read($multi);
    file_put_contents($base.'.json',json_encode(['http'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'transport'=>(int)($info['result']??0),'slow_clients'=>$slowClients,'peak_queue_bytes'=>$peakQueue]));
    $until=microtime(true)+2;while(array_sum(array_map('strlen',$buffers))>0&&microtime(true)<$until){$service();usleep(10000);}
}finally{
    foreach($clients as $client)fclose($client);
    if($multi&&$curl)curl_multi_remove_handle($multi,$curl);
    if($curl)curl_close($curl);if($multi)curl_multi_close($multi);
    fclose($server);@unlink($base.'.sock');
}
