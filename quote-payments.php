<?php
require __DIR__.'/inc/core.php';auth();require_once __DIR__.'/inc/permissions.php';require __DIR__.'/inc/layout.php';
require_once __DIR__.'/inc/revision-review.php';
require_once __DIR__.'/inc/v11.php';
$pdo=db();$id=max(0,(int)($_GET['id']??0));
$s=$pdo->prepare('SELECT q.id,q.quote_no,q.total,q.currency,c.name FROM quotes q JOIN customers c ON c.id=q.customer_id WHERE q.id=?');$s->execute([$id]);$q=$s->fetch();if(!$q){http_response_code(404);exit('Teklif bulunamadı.');}
$error='';$notice='';$ready=false;$paymentBlock='';
try{$paymentBlock=ekRevisionPaymentReason($pdo,$id);}catch(Throwable $e){$paymentBlock='Revizyon bilgileri doğrulanamadı; tahsilat kapalı.';}
try{$ready=(bool)$pdo->query("SHOW TABLES LIKE 'ek_quote_payments'")->fetchColumn();}catch(Throwable $e){}
if($_SERVER['REQUEST_METHOD']==='POST'){
 ek_require_admin();verify();
 try{
  if(!$ready)throw new RuntimeException('Tahsilat tablosu henüz hazır değil. Sistem Kontrolü bölümünden hazırlayın.');
  $amount=(string)($_POST['amount']??'');
  if(!preg_match('/^\\d{1,9}(?:[.,]\\d{1,2})?$/D',$amount))throw new RuntimeException('Geçerli bir tutar girin (en fazla 2 ondalık).');
  $cents=(int)round((float)str_replace(',','.',$amount)*100);
  if($cents<1)throw new RuntimeException('Tutar sıfırdan büyük olmalıdır.');
  $date=(string)($_POST['paid_at']??'');
  if(!ekDate($date))throw new RuntimeException('Geçerli bir tarih seçin.');
  $method=(string)($_POST['method']??'');
  if(!in_array($method,['cash','bank','card','other'],true))throw new RuntimeException('Ödeme yöntemi geçersiz.');
  $note=trim((string)($_POST['note']??''));if(mb_strlen($note)>500)throw new RuntimeException('Açıklama en fazla 500 karakter olabilir.');
  $requestKey=$_POST['payment_request']??null;
  if(!is_string($requestKey)||!preg_match('/^[a-f0-9]{64}$/D',$requestKey))throw new RuntimeException('Tahsilat formunu yenileyip tekrar deneyin.');
  $fingerprint=hash('sha256',json_encode([$cents,$date,$method,$note],JSON_THROW_ON_ERROR));
  $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$pdo->beginTransaction();
  // Acquire family locks in one order before calculating a shared balance.
  $account=ekFinancialAccount($pdo,$id);$familyIds=$account['ids'];sort($familyIds,SORT_NUMERIC);foreach($familyIds as $familyId)ekLockQuote($pdo,(int)$familyId);
  // The quote lock serializes retries. The event and receipt commit together.
  $replay=$pdo->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(payload,'$.fingerprint')) FROM v11_quote_events WHERE quote_id=? AND event_type='payment_recorded' AND actor_id=? AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.request_key'))=? LIMIT 1");
  $replay->execute([$id,(int)$_SESSION['uid'],$requestKey]);
  $previous=$replay->fetchColumn();
  if($previous!==false){
   if(!hash_equals((string)$previous,$fingerprint))throw new RuntimeException('Bu form başka bir tahsilat için kullanıldı. Sayfayı yenileyin.');
   $pdo->commit();redirect('quote-payments.php?id='.$id.'&saved=1');
  }
  $account=ekFinancialAccount($pdo,$id);$current=$account['quote'];
  if($account['effective_id']!==$id)throw new RuntimeException('Tahsilat geçerli revizyon üzerinden kaydedilmelidir.');
  if($current['currency']!==$q['currency'])throw new RuntimeException('Teklif para birimi değişti. Sayfayı yenileyin.');
  if($account['mismatch'])throw new RuntimeException('Farklı para biriminde eski ödeme var; mutabakat gerekir.');
  $already=$account['paid_cents'];
  $reason=ekRevisionPaymentReason($pdo,$id);if($reason!=='')throw new RuntimeException($reason);
  $limit=(int)round((float)$current['total']*100);
  if($limit<=0||$already+$cents>$limit)throw new RuntimeException('Tahsilat kalan bakiyeyi aşamaz.');
  $st=$pdo->prepare('INSERT INTO ek_quote_payments(quote_id,amount_cents,currency,paid_at,method,note,created_by) VALUES (?,?,?,?,?,?,?)');
  $st->execute([$id,$cents,$current['currency'],$date,$method,$note,(int)$_SESSION['uid']]);
  v11_event($id,'payment_recorded',['request_key'=>$requestKey,'fingerprint'=>$fingerprint,'payment_id'=>(int)$pdo->lastInsertId()]);
  $pdo->commit();redirect('quote-payments.php?id='.$id.'&saved=1');
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('Quote payment failed: '.$e->getMessage());$error=$e instanceof PDOException?'Tahsilat kaydedilemedi. Sistem kontrolü gerekir.':$e->getMessage();}
}
$rows=[];$paidCents=0;$mismatch=false;
if($ready){$st=$pdo->prepare('SELECT * FROM ek_quote_payments WHERE quote_id=? ORDER BY paid_at DESC,id DESC');$st->execute([$id]);$rows=$st->fetchAll();foreach($rows as $r){if($r['currency']===$q['currency'])$paidCents+=(int)$r['amount_cents'];else $mismatch=true;}}
try{$account=ekFinancialAccount($pdo,$id);$paidCents=$account['paid_cents'];$mismatch=$account['mismatch'];$financialQuote=$account['quote'];}catch(Throwable $e){$paymentBlock='Finans zinciri doğrulanamadı; tahsilat kapalı.';$financialQuote=$q;}
$totalCents=ekRevisionCents((string)$financialQuote['total']);$remaining=$totalCents-$paidCents;
top('Tahsilat Takibi');
?>
<style>.ek-pay{max-width:1150px;margin:auto}.ek-pay .panel{margin:16px 0;padding:22px;border-radius:14px}.ek-pay .stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.ek-pay .stat{padding:18px;border-radius:12px;background:#edf3fb}.ek-pay .stat strong{display:block;font-size:23px;color:#173760;margin-top:8px}.ek-pay table{width:100%;border-collapse:collapse}.ek-pay th,.ek-pay td{padding:12px;text-align:left;border-bottom:1px solid #e5eaf0}.ek-pay .fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.ek-pay label{display:block}.ek-pay input,.ek-pay select,.ek-pay textarea{width:100%;margin-top:5px}@media(max-width:700px){.ek-pay .stats,.ek-pay .fields{grid-template-columns:1fr}.ek-pay table{display:block;overflow:auto}}</style>
<div class="ek-pay"><div class="heading"><div><h1>Tahsilat Takibi</h1><p><?=h($q['quote_no'])?> · <?=h($q['name'])?></p></div><a class="btn secondary" href="view.php?id=<?=$id?>">Teklife Dön</a></div>
<?php if(!$ready):?><p class="error">Tahsilat tablosu eksik. Ayarlar → Sistem Kontrolü bölümünden eksik yardımcı tabloları hazırlayın.</p><?php endif;?>
<?php if($error):?><p class="error"><?=h($error)?></p><?php endif;?>
<?php if(isset($_GET['saved'])):?><p class="notice">Tahsilat kaydedildi.</p><?php endif;?>
<?php if($mismatch):?><p class="error">Teklifin eski para biriminde ödeme kayıtları var. Bunlar toplam bakiyeye katılmadı; finansal mutabakat yapın.</p><?php endif;?>
<?php if(isset($account)&&count($account['ids'])>1):?><p>Bu taşımanın finans zinciri tek bakiye olarak gösterilir. Makbuzlar kaynak kayıtlarında kalır; aşağıdaki ödeme geçmişi yalnız seçili teklife aittir. <a href="quote-payments.php?id=<?=(int)$account['effective_id']?>">Geçerli teklifin tahsilat ekranı</a>. <?php foreach($account['ids'] as $sourceId):if((int)$sourceId===$id)continue;?><a href="quote-payments.php?id=<?=(int)$sourceId?>">Kaynak #<?=(int)$sourceId?> ödeme geçmişi</a> <?php endforeach;?></p><?php endif;?>
<?php if($remaining<0):?><p role="status">Olası iade / fazla ödeme: <?=money(-$remaining/100,$financialQuote['currency'])?>. Bu ekran otomatik iade yapmaz.</p><?php endif;?>
<div class="stats"><div class="stat">Teklif Bedeli<strong><?=money($financialQuote['total'],$financialQuote['currency'])?></strong></div><div class="stat">Tahsil Edilen<strong><?=money($paidCents/100,$q['currency'])?></strong></div><div class="stat">Kalan Bakiye<strong><?=money(max(0,$remaining)/100,$q['currency'])?></strong></div></div>
<?php if($paymentBlock):?><p role="alert" class="error"><?=h($paymentBlock)?></p><?php endif;?>
<?php if(!$paymentBlock&&$remaining>0&&(ek_user_role()==='admin'||ek_user_role()==='administrator')):?><div class="panel"><h2>Yeni Tahsilat Kaydet</h2><p>Ödemeler teklifin <?=h($q['currency'])?> para biriminde kaydedilir. Diğer para birimlerini kur dönüşümü olmadan toplamayız.</p>
<form method="post"><input type="hidden" name="payment_request" value="<?=bin2hex(random_bytes(32))?>"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><div class="fields">
<label>Tutar (<?=h($q['currency'])?>)<input required name="amount" inputmode="decimal" placeholder="250,00" maxlength="12"></label>
<label>Ödeme Tarihi<input required type="date" name="paid_at" value="<?=h(date('Y-m-d'))?>"></label>
<label>Ödeme Yöntemi<select required name="method"><option value="bank">Havale / EFT</option><option value="cash">Nakit</option><option value="card">Kart</option><option value="other">Diğer</option></select></label>
<label>Açıklama<input name="note" maxlength="500" placeholder="Kapora / 2. taksit"></label></div><p><button type="submit" <?=$ready?'':'disabled'?>>Tahsilatı Kaydet</button></p></form></div><?php endif;?>
<div class="panel"><h2>Ödeme Geçmişi</h2><p>Durum: <strong><?=$paidCents<=0?'Ödenmedi':($remaining>0?'Kısmi ödendi':'Ödendi')?></strong></p>
<table><thead><tr><th>Tarih</th><th>Tutar</th><th>Yöntem</th><th>Açıklama</th><th>Kaydeden</th><th>Makbuz</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=h($r['paid_at'])?></td><td><?=money($r['amount_cents']/100,$r['currency'])?></td><td><?=h(['bank'=>'Havale / EFT','cash'=>'Nakit','card'=>'Kart','other'=>'Diğer'][$r['method']]??$r['method'])?></td><td><?=h($r['note'])?></td><td><?=h($r['created_by'])?></td><td><a href="payment-receipt.php?id=<?=(int)$r['id']?>">Görüntüle</a></td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="6">Henüz tahsilat kaydı yok.</td></tr><?php endif;?></tbody></table></div></div>
<?php bottom(); ?>
