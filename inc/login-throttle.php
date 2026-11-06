<?php
// Shared across PHP workers, per remote IP, outside the web root.
function ekLoginAttempt($reset=false){
 $dir=sys_get_temp_dir().'/ek-login-'.substr(hash('sha256',__DIR__),0,20);
 if(!is_dir($dir)&&!@mkdir($dir,0700)&&!is_dir($dir))throw new RuntimeException('Giriş güvenliği başlatılamadı.');
 $file=$dir.'/'.hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown')).'.json';
 $f=@fopen($file,'c+');if(!$f)throw new RuntimeException('Giriş güvenliği kullanılamıyor.');
 try{
  if(!flock($f,LOCK_EX))throw new RuntimeException('Giriş güvenliği kullanılamıyor.');
  $data=json_decode(stream_get_contents($f),true)?:['start'=>time(),'count'=>0];
  if(time()-(int)$data['start']>=900)$data=['start'=>time(),'count'=>0];
  if($reset)$data=['start'=>time(),'count'=>0];
  elseif((int)$data['count']>=10)return false;
  else $data['count']++;
  rewind($f);ftruncate($f,0);fwrite($f,json_encode($data));fflush($f);return true;
 }finally{flock($f,LOCK_UN);fclose($f);}
}
