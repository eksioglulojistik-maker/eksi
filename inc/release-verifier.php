<?php
/** Read-only integrity check against declared deployment bytes; never writes or adopts live hashes. */
function ekVerifyRelease(string $root,array $release,?string $installed):array {
 $valid=is_string($release['version']??null)&&preg_match('/^\d+\.\d+\.\d+$/D',$release['version'])&&is_string($release['build']??null)&&preg_match('/^[a-f0-9]{16}$/D',$release['build'])&&is_array($release['files']??null)&&count($release['files'])>0;
 $rows=[];$root=realpath($root);if(!$root)$valid=false;
 if(is_array($release['files']??null))foreach($release['files'] as $path=>$expected){
  $safe=is_string($path)&&preg_match('~^(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_-]+\.(?:php|css|js|png|jpg|jpeg|webp|svg|ico|woff2|txt)$~D',$path)&&!in_array(strtolower(basename($path)),['config.php','error_log'],true)&&is_string($expected)&&preg_match('/^[a-f0-9]{64}$/D',$expected);
  if(!$safe){$valid=false;$rows[]=['path'=>is_string($path)?$path:'Geçersiz yol','expected'=>'','actual'=>null,'ok'=>false];continue;}
  $file=$root?realpath($root.'/'.$path):false;$actual=($file&&str_starts_with($file,$root.DIRECTORY_SEPARATOR)&&is_file($file)&&is_readable($file))?hash_file('sha256',$file):null;
  $rows[]=['path'=>$path,'expected'=>$expected,'actual'=>is_string($actual)?$actual:null,'ok'=>is_string($actual)&&hash_equals($expected,$actual)];
 }
 if($valid&&version_compare($release['version'],'3.6.37','>='))$valid=ekVerifyReleaseSignature($release);
 $filesOk=$valid&&count(array_filter($rows,fn($r)=>!$r['ok']))===0;
 $versionOk=$valid&&$installed!==null&&$installed===$release['version'];
 return ['valid'=>(bool)$valid,'files_ok'=>$filesOk,'version_ok'=>(bool)$versionOk,'ok'=>$filesOk&&$versionOk,'rows'=>$rows];
}

/** Verify this release against the embedded release public key. */
function ekVerifyReleaseSignature(array $release):bool {
 if(!function_exists('openssl_verify')||!is_string($release['signature']??null))return false;
 $sig=base64_decode($release['signature'],true);
 if($sig===false)return false;
 $data=json_encode([$release['version'],$release['build'],$release['files']],JSON_UNESCAPED_SLASHES);
 require_once __DIR__.'/update-signature.php';
 $key=ekUpdatePublicKey();
 return is_string($data)&&openssl_verify($data,$sig,$key,OPENSSL_ALGO_SHA256)===1;
}
