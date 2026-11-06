<?php
require __DIR__.'/inc/core.php';header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
function mq_json($data,$status=200){http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE);exit;}
if(!ekSessionValid())mq_json(['error'=>'Oturumunuz sona erdi. Taslağınız bu tarayıcıda korunur.'],401);
if($_SERVER['REQUEST_METHOD']!=='POST')mq_json(['error'=>'Yöntem desteklenmiyor'],405);
if(!is_string($_POST['csrf']??null)||!hash_equals(csrf(),$_POST['csrf']))mq_json(['error'=>'Geçersiz istek.'],403);
if(($_POST['action']??'')!=='create_customer')mq_json(['error'=>'Geçersiz işlem'],400);
foreach(['name','email','phone','company','type'] as $field){
 if(isset($_POST[$field])&&!is_string($_POST[$field]))mq_json(['error'=>'Geçersiz müşteri bilgisi.'],422);
}
$name=trim((string)($_POST['name']??''));$email=trim((string)($_POST['email']??''));$phone=trim((string)($_POST['phone']??''));$company=trim((string)($_POST['company']??''));$type=($_POST['type']??'individual')==='company'?'company':'individual';
if($name===''||mb_strlen($name)>190)mq_json(['error'=>'Geçerli müşteri adı girin.'],422);
if($email!==''&&(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190))mq_json(['error'=>'E-posta geçersiz.'],422);
if(strlen($phone)>50||mb_strlen($company)>190)mq_json(['error'=>'Alanlardan biri çok uzun.'],422);
$key=$_POST['request_key']??null;
if(!is_string($key)||!preg_match('/^[a-f0-9]{32}$/D',$key))mq_json(['error'=>'Müşteri formunu yenileyip tekrar deneyin.'],422);
$fingerprint=hash('sha256',serialize([$name,$email,$phone,$company,$type]));
$previous=$_SESSION['mq_customer_requests'][$key]??null;
if($previous){
 if(!hash_equals($previous['fingerprint'],$fingerprint))mq_json(['error'=>'Bu kayıt isteği başka bilgilerle kullanıldı.'],409);
 mq_json($previous['result']);
}
try{$s=db()->prepare('INSERT INTO customers (type,name,company,email,phone) VALUES (?,?,?,?,?)');$s->execute([$type,$name,$company,$email,$phone]);$result=['ok'=>true,'id'=>(int)db()->lastInsertId(),'label'=>$name.($company?' · '.$company:'')];
$_SESSION['mq_customer_requests'][$key]=['fingerprint'=>$fingerprint,'result'=>$result];
$_SESSION['mq_customer_requests']=array_slice($_SESSION['mq_customer_requests'],-100,null,true);
mq_json($result);}catch(Throwable $e){mq_json(['error'=>'Müşteri kaydı yapılamadı.'],500);}
