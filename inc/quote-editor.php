<?php
// Tokens are scoped to the authenticated PHP session. Session locking serializes
// duplicate POSTs; the result is recorded only after the transaction commits.
function mqEditorToken(?string $posted=null):string {
 foreach($_SESSION['mq_editor_tickets']??[] as $key=>$ticket)if(($ticket['expires']??0)<time())unset($_SESSION['mq_editor_tickets'][$key]);
 if($posted!==null){if(!preg_match('/^[a-f0-9]{64}$/D',$posted)||!isset($_SESSION['mq_editor_tickets'][$posted]))throw new RuntimeException('Formun süresi dolmuş. Bilgilerinizi koruyup yeni teklif ekranını açın.');return $posted;}
 $token=bin2hex(random_bytes(32));$_SESSION['mq_editor_tickets'][$token]=['expires'=>time()+86400,'result'=>0];
 // Keep independent tabs, with a bounded session footprint.
 $_SESSION['mq_editor_tickets']=array_slice($_SESSION['mq_editor_tickets'],-100,null,true);return $token;
}
function mqEditorErrors(array $post,float $extraPrice=0.0):array {
 $errors=[];
 if(filter_var($post['customer_id']??0,FILTER_VALIDATE_INT)<1)$errors['customer_id']='Bir müşteri seçin.';
 foreach(['origin'=>'Çıkış adresi','destination'=>'Varış adresi'] as $key=>$label)if(trim((string)($post[$key]??''))===''||mb_strlen((string)$post[$key])>4000)$errors[$key]=$label.' girin (en fazla 4000 karakter).';
 if(!in_array($post['transport_type']??'', ['road','sea_fcl','sea_lcl'],true))$errors['transport_type']='Bir taşıma yöntemi seçin.';
 foreach(['volume'=>['Hacim',100000],'unit_price'=>['Birim fiyat',999999999],'discount'=>['İndirim',999999999]] as $key=>$limits){try{$n=ekNumber($post[$key]??0,$limits[0],$limits[1]);if($key==='volume'&&$n<=0)$errors[$key]='Hacim sıfırdan büyük olmalıdır.';}catch(RuntimeException $e){$errors[$key]=$e->getMessage();}}
 if(!array_intersect_key($errors,array_flip(['volume','unit_price','discount']))&&is_numeric($post['volume']??null)&&is_numeric($post['unit_price']??null)&&is_numeric($post['discount']??0)&& (float)($post['discount']??0)>(float)$post['volume']*(float)$post['unit_price']+$extraPrice)$errors['discount']='İndirim, hizmet bedelinden büyük olamaz.';
 foreach(['valid_until'=>'Geçerlilik tarihi','move_date'=>'Taşınma tarihi'] as $key=>$label)if(($post[$key]??'')!==''&&!ekDate($post[$key]))$errors[$key]=$label.' geçersiz.';
 return $errors;
}
