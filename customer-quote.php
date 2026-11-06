<?php
require __DIR__.'/inc/core.php';require_once __DIR__.'/inc/v11.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');require_once __DIR__.'/inc/quote-richtext.php';require_once __DIR__.'/inc/moving-workflow.php';
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);$token=(string)($_GET['token']??'');
if(!$id||!preg_match('/^[a-f0-9]{64}$/D',$token)){http_response_code(404);exit('Teklif bağlantısı geçersiz.');}
$pdo=db();$s=$pdo->prepare('SELECT q.*,c.name,c.company,c.email,c.phone,c.address FROM quotes q JOIN customers c ON c.id=q.customer_id WHERE q.id=?');$s->execute([$id]);$q=$s->fetch();
if(!$q||!hash_equals(v11QuoteShareToken($q,ekRevisionMeta($pdo,$id)),$token)){http_response_code(404);exit('Teklif bağlantısı geçersiz.');}
$d=$pdo->prepare('SELECT * FROM quote_details WHERE quote_id=?');$d->execute([$id]);$detail=$d->fetch()?:[];$error='';$success='';
// An offer without a deadline must not be accepted from a public link.
$deadline=(string)($q['valid_until']??'');
$deadlineValid=(bool)preg_match('/^\d{4}-\d{2}-\d{2}$/D',$deadline);
if($deadlineValid){$parts=explode('-',$deadline);$deadlineValid=checkdate((int)$parts[1],(int)$parts[2],(int)$parts[0]);}
$expired=(!$deadlineValid || $deadline<date('Y-m-d'));
$deadlineMissing=!$deadlineValid;
// Give recipients time to retrieve the offer after the response deadline.
if(!$deadlineValid||date('Y-m-d')>(new DateTimeImmutable($deadline))->modify('+30 days')->format('Y-m-d')){
 http_response_code(410);exit('Teklif bağlantısının görüntüleme süresi dolmuştur. Lütfen firmayla iletişime geçin.');
}

if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  verify();
  if($expired)throw new RuntimeException('Teklifin geçerlilik tarihi eksik, geçersiz veya süresi dolmuş.');
  if(!in_array($q['status'],['sent','priced'],true))throw new RuntimeException('Bu teklif şu anda yanıtlanamaz.');
  $choice=(string)($_POST['decision']??'');if(!in_array($choice,['accepted','rejected'],true))throw new RuntimeException('Geçersiz işlem.');
  if($choice==='accepted'&&empty($_POST['confirmation']))throw new RuntimeException('Onay kutusunu işaretleyin.');
  $pdo->beginTransaction();
  $lock=$pdo->prepare('SELECT q.*,c.name FROM quotes q JOIN customers c ON c.id=q.customer_id WHERE q.id=? FOR UPDATE');$lock->execute([$id]);$locked=$lock->fetch();
  if($locked&&!hash_equals(v11QuoteShareToken($locked,ekRevisionMeta($pdo,$id)),$token))throw new RuntimeException('Teklif bağlantısı yenilendi.');
  if(!$locked||!in_array($locked['status'],['sent','priced'],true)||(empty($locked['valid_until'])||$locked['valid_until']<date('Y-m-d')))throw new RuntimeException('Teklif durumu veya geçerliliği değişmiş.');
  ekAssertQuoteEditable($pdo,$locked);
  $update=$pdo->prepare("UPDATE quotes SET status=? WHERE id=? AND status IN ('sent','priced')");$update->execute([$choice,$id]);
  if(!$update->rowCount())throw new RuntimeException('Teklif durumu değişmiş, sayfayı yenileyin.');
  $planned=true;if($choice==='accepted')$planned=ekAcceptanceDocuments($pdo,$locked,'customer');
  // Notifications are informational: a missing optional table must never block a customer's decision.
  try{
   $users=$pdo->query("SELECT id FROM users WHERE LOWER(role) IN ('admin','administrator')")->fetchAll(PDO::FETCH_COLUMN);
   if($users){
    $notify=$pdo->prepare('INSERT INTO ek_notifications (recipient_id,quote_id,event_type,title,body) VALUES (?,?,?,?,?)');
    foreach($users as $recipient){
     $notify->execute([(int)$recipient,$id,$choice==='accepted'?'quote_accepted':'quote_rejected',$choice==='accepted'?'Müşteri teklifi onayladı':'Müşteri teklifi reddetti',($locked['name']??'Müşteri').' · '.($locked['quote_no']??'Teklif')]);
    }
   }
  }catch(PDOException $notificationError){error_log('Optional notification insert: '.$notificationError->getMessage());}
  $pdo->commit();
  $q['status']=$choice;$success=$choice==='accepted'?'Teklif kabul edildi. Sözleşme taslağı oluşturuldu.'.($planned?'':' Taşınma tarihi yetkili tarafından planlanacak.'):'Teklif reddedildi.';
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('Customer decision failed: '.$e->getMessage());$error='İşlem tamamlanamadı. Teklif durumunu yenileyip tekrar deneyin.';}
}
?><!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><meta name="robots" content="noindex,nofollow"><title>Teklif <?=h($q['quote_no'])?> • EKŞİOĞLU</title><link rel="stylesheet" href="assets/style.css?v=331301"></head><body class="public-offer"><main class="public-wrap"><div class="public-actions no-print"><strong>EKŞİOĞLU LOJİSTİK · Teklifiniz</strong><button onclick="window.print()">Yazdır / PDF Kaydet</button></div>
<?php if($error):?><p class="error no-print"><?=h($error)?></p><?php endif;?><?php if($success):?><p class="notice no-print"><?=h($success)?></p><?php endif;?>
<?php include __DIR__.'/inc/quote-document.php'; ?>
<section class="public-decision no-print"><h2>Teklif Yanıtı</h2><p>Teklif durumu: <strong><?=h(statusText($q['status']))?></strong></p>
<?php if(in_array($q['status'],['sent','priced'],true)&&!$expired):?><form method="post" action="?id=<?=$id?>&amp;token=<?=h($token)?>"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><label><input type="checkbox" name="confirmation" value="1" style="width:auto"> Teklif koşullarını okudum ve kabul ediyorum.</label><div class="actionbar"><button type="submit" name="decision" value="accepted" onclick="return confirm('Teklifi kabul ediyor musunuz?')">Teklifi Kabul Et</button><button class="danger" type="submit" name="decision" value="rejected" onclick="return confirm('Teklifi reddediyor musunuz?')">Teklifi Reddet</button></div></form>
<?php elseif($expired):?><p><?= $deadlineMissing ? 'Bu teklifin geçerlilik tarihi tanımlı değil. Lütfen firmadan güncel teklif isteyin.' : 'Bu teklifin geçerlilik süresi dolmuştur.' ?></p><?php else:?><p>Bu teklif için yanıt işlemi kapalıdır.</p><?php endif;?></section></main></body></html>