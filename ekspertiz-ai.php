<?php
require __DIR__.'/inc/core.php';auth();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
function aiReply($status,$data){http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST')aiReply(405,['ok'=>false,'error'=>'Yalnızca POST isteği kabul edilir.']);
verify();
$photoId=(int)($_POST['photo_id']??0);if($photoId<1)aiReply(400,['ok'=>false,'error'=>'Fotoğraf seçilmedi.']);
$q=db()->prepare('SELECT p.file_path,p.mime,s.assigned_user_id,s.status FROM ek_survey_photos p JOIN ek_surveys s ON s.id=p.survey_id WHERE p.id=?');$q->execute([$photoId]);$r=$q->fetch();
if(!$r || (!ekAdmin() && (int)$r['assigned_user_id']!==(int)$_SESSION['uid']))aiReply(403,['ok'=>false,'error'=>'Erişim yetkiniz yok.']);
if($r['status']==='approved')aiReply(409,['ok'=>false,'error'=>'Onaylı ekspertiz analiz edilemez.']);
require_once __DIR__.'/inc/ekspertiz-ai-key.php';
$key=ekSurveyApiKey();
if($key==='')aiReply(503,['ok'=>false,'error'=>'Yapay zekâ hizmeti henüz yapılandırılmadı. Yapay zekâ anahtarı henüz tanımlanmadı. Yönetici olarak Ekspertiz AI Ayarları sayfasını açın.']);
if(!function_exists('curl_init'))aiReply(503,['ok'=>false,'error'=>'Sunucuda PHP cURL uzantısı etkin değil.']);
$filename=(string)$r['file_path'];if(!preg_match('/^[a-f0-9]{40}\.(jpg|png|webp)$/D',$filename))aiReply(400,['ok'=>false,'error'=>'Fotoğraf yolu geçersiz.']);
$path=__DIR__.'/survey_uploads/'.$filename;if(!is_file($path)||is_link($path))aiReply(404,['ok'=>false,'error'=>'Fotoğraf bulunamadı.']);
if(filesize($path)>8*1024*1024)aiReply(413,['ok'=>false,'error'=>'Fotoğraf çok büyük.']);
$mime=(string)$r['mime'];if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))aiReply(415,['ok'=>false,'error'=>'Fotoğraf biçimi desteklenmiyor.']);
$image=base64_encode(file_get_contents($path));
$payload=['model'=>'gpt-4o-mini','temperature'=>0.1,'max_tokens'=>1000,'response_format'=>['type'=>'json_object'],'messages'=>[
 ['role'=>'system','content'=>'You assist a professional household moving surveyor. Return ONLY JSON object {"items":[{"name":"Turkish item name","quantity":1,"unit_volume":0.5}]}. Identify only clearly visible distinct furniture and household goods. Do not invent occluded items. unit_volume is approximate packed transport volume in cubic meters per item, NOT precise measurement. If uncertain, omit. Limit 20 entries. No personal data, no faces, no addresses.'],
 ['role'=>'user','content'=>[['type'=>'text','text'=>'Bu oda fotoğrafındaki taşınacak eşyaları belirle. Yalnızca görünür eşyaları listele, adet ve yaklaşık tekil taşıma hacmi (m³) öner. Ölçek belirsiz olduğundan tahminler yaklaşık olsun.'],['type'=>'image_url','image_url'=>['url'=>'data:'.$mime.';base64,'.$image,'detail'=>'low']]]]
 ]];
$ch=curl_init('https://api.openai.com/v1/chat/completions');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>65,CURLOPT_SSL_VERIFYPEER=>true]);
$response=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
if($response===false||$http<200||$http>=300)aiReply(502,['ok'=>false,'error'=>'Yapay zekâ servisine ulaşılamadı. API anahtarı, kota ve bağlantıyı kontrol edin.']);
$outer=json_decode($response,true);$content=$outer['choices'][0]['message']['content']??'';$result=json_decode($content,true);
if(!is_array($result)||!is_array($result['items']??null))aiReply(502,['ok'=>false,'error'=>'Yapay zekâ geçerli bir eşya listesi döndürmedi.']);
$items=[];foreach(array_slice($result['items'],0,20) as $it){
 if(!is_array($it))continue;$name=mb_substr(trim((string)($it['name']??'')),0,190);$qty=(int)($it['quantity']??0);$vol=(float)($it['unit_volume']??-1);
 if($name===''||$qty<1||$qty>500||!is_finite($vol)||$vol<0||$vol>100)continue;
 $items[]=['name'=>$name,'quantity'=>$qty,'unit_volume'=>round($vol,3)];
}
aiReply(200,['ok'=>true,'items'=>$items]);
