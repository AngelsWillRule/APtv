<?php
// Local fan-out: one provider connection for every channel/provider pair.
function openapLiveRelayKey(string $provider, string $channel): string
{
    return '/var/lib/openap-recording-state/live-'.substr(hash('sha256',$provider."\0".$channel),0,32);
}
function openapLiveRelayConsume(string $provider, string $channel, callable $write, callable $stop): void
{
    $base=openapLiveRelayKey($provider,$channel);
    $socket=@stream_socket_client('unix://'.$base.'.sock',$errno,$error,0.2);
    if(!$socket){
        $command='nohup /usr/bin/php '.escapeshellarg('/usr/local/lib/openap-recording/iptv_live_relay.php').' '.escapeshellarg($provider).' '.escapeshellarg($channel).' </dev/null >/dev/null 2>&1 &';
        exec($command);
        $until=microtime(true)+5;
        do{if($stop())return;usleep(100000);$socket=@stream_socket_client('unix://'.$base.'.sock',$errno,$error,0.2);}while(!$socket&&microtime(true)<$until);
    }
    if(!$socket)throw new RuntimeException('[LIVE_CONNECT] Collegamento al flusso condiviso non riuscito.');
    stream_set_blocking($socket,false);$last=time();
    try{
        while(!$stop()){
            $read=[$socket];$writeSet=[];$except=[];
            if(@stream_select($read,$writeSet,$except,0,200000)>0){
                $chunk=fread($socket,65536);
                if($chunk===false||($chunk===''&&feof($socket)))break;
                if($chunk!==''){$last=time();if($write($chunk)!==strlen($chunk))return;}
            }
            if(time()-$last>30)break;
        }
        if(!$stop()){
            $state=json_decode((string)@file_get_contents($base.'.json'),true);
            $http=(int)($state['http']??0);$transport=(int)($state['transport']??0);
            if((int)($state['slow_clients']??0)>0)throw new RuntimeException('[LIVE_QUEUE_LIMIT] Il lettore non smaltisce i dati in arrivo: superato il limite della coda condivisa.');
            throw new RuntimeException('[LIVE_DISCONNECTED] Flusso interrotto; HTTP '.$http.', trasporto '.$transport.'.');
        }
    }finally{fclose($socket);}
}
