<?php
if (PHP_SAPI !== 'cli') exit(64);
require_once '/var/www/html/includes/iptv_api.php';
$server = @stream_socket_server('tcp://127.0.0.1:8091', $errorNumber, $errorMessage);
if (!is_resource($server)) exit(65);
pcntl_async_signals(true);
pcntl_signal(SIGCHLD, static function (): void { while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {} });
while (true) {
    $client = @stream_socket_accept($server, -1);
    if (!is_resource($client)) continue;
    $child = pcntl_fork();
    if ($child < 0) { fclose($client); continue; }
    if ($child > 0) { fclose($client); continue; }
    fclose($server);
    stream_set_timeout($client, 10);
    @stream_set_write_buffer($client, 0);
    $request = '';
    while (!str_contains($request, "\r\n\r\n") && strlen($request) < 32768 && !feof($client)) $request .= (string) fgets($client, 4096);
    $target = preg_match('~^(?:GET|HEAD)\s+(\S+)\s+HTTP/~', $request, $match) ? $match[1] : '';
    parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
    $upstream = openapAptvOpenUrl((string) ($query['token'] ?? ''));
    if (!$upstream) { fwrite($client, "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n"); fclose($client); exit(0); }
    $path=(string)parse_url($target,PHP_URL_PATH);
    if(preg_match('~/(?:aptv|iptv)/vod-fmp4$~',$path)){
        $input='http://127.0.0.1:8091/movie?token='.rawurlencode((string)$query['token']);
        $command=['/usr/bin/ffmpeg','-nostdin','-hide_banner','-loglevel','warning'];
        $start=max(0,min(86400,(int)($query['start']??0)));if($start>0)$command=array_merge($command,['-ss',(string)$start]);
        $command=array_merge($command,['-readrate','1','-readrate_initial_burst','12','-i',$input,'-map','0:v:0','-map','0:a:0','-c:v','copy','-tag:v','hvc1','-c:a','aac','-b:a','192k','-ac','2','-ar','48000','-movflags','+frag_keyframe+empty_moov+default_base_moof','-f','mp4','pipe:1']);
        $log=(preg_match('/^[a-f0-9]{32}$/',(string)($query['token']??''))?'/run/openap-iptv-vod-remux/fmp4-'.(string)$query['token'].'.log':'/dev/null');
        $ffmpeg=proc_open($command,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file',$log,'a']],$pipes);sodium_memzero($input);sodium_memzero($upstream);
        if(!is_resource($ffmpeg)||!isset($pipes[1])){fwrite($client,"HTTP/1.1 503 Service Unavailable\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");fclose($client);exit(0);}
        fwrite($client,"HTTP/1.1 200 OK\r\nContent-Type: video/mp4\r\nCache-Control: no-store\r\nAccept-Ranges: none\r\nConnection: close\r\n\r\n");
        while(!feof($pipes[1])){$chunk=fread($pipes[1],65536);if($chunk===false)break;if($chunk!==''&&!openapAptvWriteSocket($client,$chunk))break;}
        fclose($pipes[1]);proc_terminate($ffmpeg);proc_close($ffmpeg);fclose($client);exit(0);
    }
    if(preg_match('~/(?:aptv|iptv)/vod-audio$~',$path)){
        $start=max(0,min(86400,(int)($query['start']??0)));$audio=max(0,min(31,(int)($query['audio']??0)));$input='http://127.0.0.1:8091/movie?token='.rawurlencode((string)$query['token']);$command=['/usr/bin/ffmpeg','-nostdin','-hide_banner','-loglevel','error'];if($start>0)$command=array_merge($command,['-ss',(string)$start]);$command=array_merge($command,['-readrate','1','-readrate_initial_burst','12','-i',$input,'-map','0:v:0','-map','0:a:'.$audio,'-c','copy','-f','matroska','pipe:1']);$ffmpeg=proc_open($command,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes);sodium_memzero($input);sodium_memzero($upstream);if(!is_resource($ffmpeg)||!isset($pipes[1])){fwrite($client,"HTTP/1.1 503 Service Unavailable\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");fclose($client);exit(0);}fwrite($client,"HTTP/1.1 200 OK\r\nContent-Type: video/x-matroska\r\nCache-Control: no-store\r\nConnection: close\r\n\r\n");while(!feof($pipes[1])){$chunk=fread($pipes[1],65536);if($chunk===false)break;if($chunk!==''&&!openapAptvWriteSocket($client,$chunk))break;}fclose($pipes[1]);proc_terminate($ffmpeg);proc_close($ffmpeg);fclose($client);exit(0);
    }
    $range = '0-';
    if (preg_match('/\r\nRange:\s*bytes=(\d*-\d*)\r\n/i', $request, $match)) $range = $match[1];
    $status = 502; $responseHeaders = []; $headersWritten = false;
    $statsDirectory=null;$statsFile=null;$transferStarted=microtime(true);$transferUpdated=$transferStarted;$transferBytes=0;$transferSamples=[];$transferRollingRates=[];
    $session=(string)($query['session']??'');
    if(preg_match('/^[a-f0-9]{32}$/',$session)){$candidate='/run/openap-iptv-vod-remux/progressive-stats/'.$session;if(is_dir($candidate)&&is_writable($candidate)){$statsDirectory=$candidate;$statsFile=$candidate.'/transfer-'.bin2hex(random_bytes(8)).'.json';}}
    $writeStats=static function(bool $active)use(&$transferBytes,$transferStarted,&$transferUpdated,&$transferSamples,&$transferRollingRates,&$status,$range,$statsDirectory,$statsFile):void{
        if(!$statsDirectory||!$statsFile)return;
        $now=microtime(true);$elapsed=max(.001,$now-$transferStarted);
        $transferSamples[]=['time'=>$now,'bytes'=>$transferBytes];
        while(count($transferSamples)>2&&(float)$transferSamples[1]['time']<$now-10)array_shift($transferSamples);
        $baseline=$transferSamples[0];$windowElapsed=max(.001,$now-(float)$baseline['time']);$windowBytes=max(0,$transferBytes-(int)$baseline['bytes']);
        $recentBps=$windowElapsed>=1?(int)round($windowBytes*8/$windowElapsed):0;
        if($windowElapsed>=9.5){$transferRollingRates[]=['time'=>$now,'bps'=>$recentBps];}
        while($transferRollingRates&&(float)$transferRollingRates[0]['time']<$now-60)array_shift($transferRollingRates);
        $minimumBps=$transferRollingRates?min(array_column($transferRollingRates,'bps')):0;
        $payload=['started'=>$transferStarted,'updated'=>$now,'elapsed'=>$elapsed,'bytes'=>$transferBytes,'transfer_bps'=>(int)round($transferBytes*8/$elapsed),'transfer_10s_bps'=>$recentBps,'transfer_min_60s_bps'=>(int)$minimumBps,'transfer_window_seconds'=>$windowElapsed,'minimum_available'=>(bool)$transferRollingRates,'status'=>$status,'range'=>$range,'active'=>$active];
        $temporary=$statsFile.'.new';
        if(@file_put_contents($temporary,json_encode($payload,JSON_UNESCAPED_SLASHES),LOCK_EX)!==false){@chmod($temporary,0600);@rename($temporary,$statsFile);@touch($statsDirectory);}
        $transferUpdated=$now;
    };
    $writeStats(true);
    $curl = curl_init($upstream);
    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_TIMEOUT => 0, CURLOPT_BUFFERSIZE => 1048576, CURLOPT_TCP_NODELAY => true, CURLOPT_RANGE => $range,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$status, &$responseHeaders, &$headersWritten, $client): int {
            $trimmed = trim($line);
            if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $trimmed, $match)) { $status = (int) $match[1]; $responseHeaders = []; $headersWritten = false; }
            elseif (preg_match('/^(Content-Type|Content-Length|Content-Range|Last-Modified|ETag):\s*(.+)$/i', $trimmed, $match)) $responseHeaders[strtolower($match[1])] = $match[2];
            elseif ($trimmed === '' && !$headersWritten && $status >= 200 && $status < 300) {
                $lines = ['HTTP/1.1 ' . $status . ($status === 206 ? ' Partial Content' : ' OK'), 'Accept-Ranges: bytes', 'Cache-Control: private, no-store', 'X-Content-Type-Options: nosniff', 'Connection: close'];
                foreach ($responseHeaders as $name => $value) $lines[] = ucwords($name, '-') . ': ' . $value;
                fwrite($client, implode("\r\n", $lines) . "\r\n\r\n"); $headersWritten = true;
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($client,&$transferBytes,&$transferUpdated,$writeStats): int { $total = 0; $length = strlen($chunk); while ($total < $length) { $written = @fwrite($client, $total === 0 ? $chunk : substr($chunk, $total)); if ($written === false || $written === 0) return 0; $total += $written; }$transferBytes+=$total;if(microtime(true)-$transferUpdated>=1)$writeStats(true);return $total; },
    ]);
    curl_exec($curl);$writeStats(false);curl_close($curl); sodium_memzero($upstream); fclose($client); exit(0);
}

function openapAptvWriteSocket($client,string $chunk): bool
{
    $offset=0;$length=strlen($chunk);while($offset<$length){$written=@fwrite($client,$offset===0?$chunk:substr($chunk,$offset));if($written===false||$written===0)return false;$offset+=$written;}return true;
}
