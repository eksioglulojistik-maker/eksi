<?php
// Public, read-only contract rendering: use the EXACT same premium print template as the administrator.
require __DIR__.'/inc/v11.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
$token=(string)($_GET['token']??'');
if(!preg_match('/^[a-f0-9]{64}$/D',$token)){http_response_code(404);exit('Belge bağlantısı geçersiz.');}
try{
 $st=db()->prepare('SELECT l.quote_id,l.contract_hash,c.id AS contract_id,c.language,c.body,c.created_at AS contract_created_at,l.revoked_at,(l.expires_at>NOW()) AS unexpired FROM ek_contract_share_links l JOIN v11_contracts c ON c.quote_id=l.quote_id WHERE l.token_hash=? LIMIT 1');
 $st->execute([hash('sha256',$token)]);$link=$st->fetch(PDO::FETCH_ASSOC);
 if(!$link){http_response_code(404);exit('Bağlantı geçersiz veya sözleşme imzalanmış.');}
 $hash=hash('sha256',$link['language']."\n".$link['body']);
 if(!hash_equals($link['contract_hash'],$hash)){http_response_code(410);exit('Sözleşme güncellenmiş. Yeni bağlantı isteyin.');}
 if(!empty($link['revoked_at'])||!(int)$link['unexpired']){
  $check=db()->prepare('SELECT contract_hash FROM ek_contract_signatures WHERE quote_id=? LIMIT 1');
  $check->execute([(int)$link['quote_id']]);
  $signedHash=$check->fetchColumn();
  if(!is_string($signedHash)||!hash_equals($hash,$signedHash)){
   http_response_code(!empty($link['revoked_at'])?403:410);exit('Bu sözleşme bağlantısı iptal edilmiş veya süresi dolmuş.');
  }
 }
 $id=(int)$link['quote_id'];
 $st=db()->prepare('SELECT q.*,cu.name,cu.company FROM quotes q JOIN customers cu ON cu.id=q.customer_id WHERE q.id=? LIMIT 1');
 $st->execute([$id]);$q=$st->fetch(PDO::FETCH_ASSOC);
 if(!$q){http_response_code(404);exit('Belge bulunamadı.');}
 $contract=['id'=>$link['contract_id'],'language'=>$link['language'],'body'=>$link['body'],'created_at'=>$link['contract_created_at']];
 $lang=$link['language'];
 // The same template is included by v11-print.php for the admin PDF view.
 ob_start();require __DIR__.'/inc/contract-premium-print.php';$html=ob_get_clean();$html=str_replace('</style>', '.actions{display:none!important}</style>', $html);echo $html;
}catch(Throwable $e){http_response_code(500);exit('Sözleşme görüntülenemedi. Lütfen firmayla iletişime geçin.');}
