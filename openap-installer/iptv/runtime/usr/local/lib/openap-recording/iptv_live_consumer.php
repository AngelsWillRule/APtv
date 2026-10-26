<?php
if(PHP_SAPI!=='cli'||count($argv)!==3)exit(64);
require '/var/www/html/includes/live_relay.php';
try{
    openapLiveRelayConsume((string)$argv[1],(string)$argv[2],static function(string $chunk):int{
        $length=strlen($chunk);$offset=0;
        while($offset<$length){$n=@fwrite(STDOUT,substr($chunk,$offset));if(!$n)return 0;$offset+=$n;}return $length;
    },static function():bool{return false;});
}catch(Throwable $error){exit(1);}
