<?php
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','samesite'=>'Lax','path'=>'/']);
ini_set('display_errors','0');
ini_set('log_errors','1');
session_start();
date_default_timezone_set('Europe/Istanbul');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');
$configFile=dirname(__DIR__).'/config.php';
if(!file_exists($configFile)){header('Location: install.php');exit;}
require $configFile;
function db(){static $pdo=null;if(!$pdo){$pdo=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}return $pdo;}
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function csrf(){if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function verify(){if(!is_string($_POST['csrf']??null)||!hash_equals(csrf(),$_POST['csrf'])){http_response_code(403);exit('Geçersiz istek.');}}
function ekSessionProof(string $passwordHash):string {
 return hash_hmac('sha256',$passwordHash,DB_PASS);
}
function ekSessionValid():bool {
 static $valid=null;
 if($valid!==null)return $valid;
 if(empty($_SESSION['uid'])||!is_string($_SESSION['account_proof']??null)){
  if(!empty($_SESSION['uid']))$_SESSION=[];
  return $valid=false;
 }
 $s=db()->prepare('SELECT password_hash FROM users WHERE id=?');
 $s->execute([(int)$_SESSION['uid']]);$hash=$s->fetchColumn();
 $valid=is_string($hash)&&hash_equals(ekSessionProof($hash),$_SESSION['account_proof']);
 if(!$valid)$_SESSION=[];
 return $valid;
}
function auth(){if(!ekSessionValid())redirect('login.php');}
function redirect($url){header('Location: '.$url);exit;}
function money($n,$currency){return number_format((float)$n,2,',','.').' '.h($currency);}
function statusText($s){return ['draft'=>'Taslak','priced'=>'Fiyatlandırıldı','sent'=>'Gönderildi','accepted'=>'Onaylandı','rejected'=>'Reddedildi','expired'=>'Süresi doldu','pending'=>'İşlem bekleyen'][$s]??$s;}
function nextNumber($pdo){
 $year=date('Y');
 $s=$pdo->prepare('INSERT INTO counters (year,last_number) VALUES (?,LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE last_number=LAST_INSERT_ID(last_number+1)');
 $s->execute([$year]);$n=(int)$pdo->lastInsertId();
 if($n<1)throw new RuntimeException('Teklif numarası üretilemedi.');
 return 'EK-'.$year.'-'.str_pad((string)$n,5,'0',STR_PAD_LEFT);
}
function ekDate($v){return is_string($v)&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v)&&checkdate((int)substr($v,5,2),(int)substr($v,8,2),(int)substr($v,0,4));}
function ekNumber($v,$label,$max=999999999){
 if(!is_scalar($v)||!is_numeric($v)||!is_finite((float)$v)||(float)$v<0||(float)$v>$max)throw new RuntimeException($label.' geçersiz.');
 return (float)$v;
}
function ekAdmin(){
 if(empty($_SESSION['uid']))return false;
 $s=db()->prepare('SELECT role FROM users WHERE id=?');$s->execute([(int)$_SESSION['uid']]);
 return in_array(strtolower((string)$s->fetchColumn()),['admin','administrator'],true);
}
