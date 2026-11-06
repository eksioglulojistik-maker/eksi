<?php
require __DIR__.'/inc/v11.php';
require_once __DIR__.'/inc/quote-share-display.php';
require_once __DIR__.'/inc/moving-file.php';
require_once __DIR__.'/inc/moving-workflow.php';
require_once __DIR__.'/inc/quote-richtext.php';
auth();
$pdo=db();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id||$id<1){http_response_code(400);exit('Geçersiz teklif.');}
$q=v11_quote($id);
if(!$q){http_response_code(404);exit('Teklif bulunamadı.');}
$admin=ekAdmin();
$acceptError='';
$loadingDate=null;
$acceptStatuses=['draft','priced','sent'];
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!$admin){http_response_code(403);exit('Yönetici yetkisi gerekir.');}
 verify();
 if(($_POST['action']??'')!=='accept'){http_response_code(400);exit('Geçersiz işlem.');}
 v11_require();
 try{
  $rawDate=$_POST['loading_date']??'';
  if($rawDate!==''&&!ekDate($rawDate))throw new RuntimeException('Geçerli bir yükleme tarihi girin.');
  $loadingDate=$rawDate;
  $pdo->beginTransaction();
  $locked=ekLockQuote($pdo,$id);
  if(ekQuoteShareArchived($pdo,$id))throw new RuntimeException('Arşivdeki teklif kabul edilemez.');
  if(!in_array($locked['status'],$acceptStatuses,true))throw new RuntimeException('Bu teklifin durumu kabul kaydına izin vermiyor.');
  if(!empty($locked['valid_until'])&&$locked['valid_until']<date('Y-m-d'))throw new RuntimeException('Teklifin geçerlilik süresi dolmuş.');
  ekAssertQuoteEditable($pdo,$locked);
  $locked['name']=v11_quote($id)['name'];
  $stmt=$pdo->prepare("UPDATE quotes SET status='accepted' WHERE id=?");
  $stmt->execute([$id]);
  ekAcceptanceDocuments($pdo,$locked,'admin',$loadingDate===''?null:$loadingDate);
  $pdo->commit();
  header('Location: quote-actions.php?id='.$id.'&accepted=1',true,303);
  exit;
 }catch(Throwable $e){
  if($pdo->inTransaction())$pdo->rollBack();
  http_response_code(409);
  $acceptError=$e instanceof PDOException?'Kabul kaydedilemedi. Kayıtlarınız korundu.':$e->getMessage();
  $q=v11_quote($id);
  if(!$q){http_response_code(404);exit('Teklif bulunamadı.');}
 }
}
$meta=ekRevisionMeta($pdo,$id);
$activeRevision=(int)($meta['revision_effective_child_id']??0);
$protected=ekQuoteProtectionReason($pdo,$q);
$archiveReady=ekTableExists($pdo,'quote_archives');
$isArchived=$archiveReady&&ekQuoteShareArchived($pdo,$id);
$archiveAllowed=$admin&&$archiveReady&&!$activeRevision&&empty($meta['revision_activated'])&&empty($meta['revision_pending_id'])&&($meta['revision_offer']['state']??'')!=='offered';
$accepted=in_array($q['status'],['accepted','approved','completed'],true);
$hasMove=false;$hasContract=false;
$workflowReady=v11_ready();
if($workflowReady){
 $s=$pdo->prepare('SELECT EXISTS(SELECT 1 FROM v11_moves WHERE quote_id=?), EXISTS(SELECT 1 FROM v11_contracts WHERE quote_id=?)');
 $s->execute([$id,$id]);[$hasMove,$hasContract]=$s->fetch(PDO::FETCH_NUM);
 if($accepted&&!empty($meta['revision_activated'])){
  try{$hasMove=!empty(ekMoveContext($pdo,$id)['move']);}catch(RuntimeException $e){$hasMove=false;}
 }
}
$preview='view.php?id='.$id;
$shareBlock=ekQuoteShareBlock($q,$meta,v11_link($q),$isArchived);
$canShare=!$isArchived&&$shareBlock==='';
$canAccept=$workflowReady&&$admin&&!$isArchived&&!$protected&&empty($meta['revision_parent_id'])&&in_array($q['status'],$acceptStatuses,true)
 && (empty($q['valid_until'])||$q['valid_until']>=date('Y-m-d'));
$primary=match(true){
 $isArchived=>null,
 $activeRevision>0=>['view.php?id='.$activeRevision,'Geçerli Revizyonu Aç'],
 $accepted&&$hasMove=>['move-file.php?id='.$id,'Taşınma Dosyasını Aç'],
 $accepted=>['v11-calendar.php?edit='.$id,'Taşınmayı Planla'],
 $canShare=>[$preview.'#customer-share','Müşteriye Gönder'],
 $protected!==''=>['quote.php?id='.$id,'Revizyon İşlemleri'],
 default=>['quote.php?id='.$id,'Teklifi Düzenle'],
};
require __DIR__.'/inc/layout.php';
top('Teklif İşlemleri');
?>
<style>
.qa-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(170px,100%),1fr));gap:12px;margin:16px 0}
.qa-summary div{min-width:0;overflow-wrap:anywhere}.qa-summary dt{color:#475569;font-size:13px}.qa-summary dd{margin:4px 0 0;font-weight:650}
.qa-groups{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr));gap:12px;margin-top:16px}
.qa-group{margin:0;min-width:0}.qa-group summary{font-weight:700;cursor:pointer;padding:7px 0}
.qa-group ul{margin:8px 0 0;padding:0;list-style:none}.qa-group li+li{border-top:1px solid #e2e8f0}
.qa-group li a{display:block;padding:12px 0;overflow-wrap:anywhere}.qa-archive{margin-top:16px}
</style>
<div class="heading"><div><h1>Teklif İşlemleri</h1><p><?=h($q['quote_no'])?> · <?=h($q['name'])?></p></div>
<a class="btn secondary" href="quotes.php<?=$isArchived?'?archived=1':''?>">← Teklifler</a></div>
<?php if($acceptError):?><p class="error" role="alert"><?=h($acceptError)?></p><?php endif;?>
<?php if($accepted&&isset($_GET['accepted'])):?><p class="notice" role="status">Teklif kabulü kaydedildi. Sözleşme ve taşınma durumunu aşağıdan kontrol edebilirsiniz.</p><?php endif;?>
<section class="panel" aria-label="Teklif özeti">
<dl class="qa-summary">
<div><dt>Çıkış</dt><dd><?=h($q['origin']?:'—')?></dd></div>
<div><dt>Varış</dt><dd><?=h($q['destination']?:'—')?></dd></div>
<div><dt>Hacim</dt><dd><?=number_format((float)$q['volume'],2,',','.')?> m³</dd></div>
<div><dt>Toplam</dt><dd><?=money($q['total'],$q['currency'])?></dd></div>
<div><dt>Durum</dt><dd><?=h($isArchived?'Arşivde':statusText($q['status']))?></dd></div>
<div><dt>Geçerlilik</dt><dd><?=h(v11_date($q['valid_until']))?></dd></div>
</dl>
<div class="actionbar">
<?php if($primary):?><a class="btn" href="<?=h($primary[0])?>"><?=h($primary[1])?></a><?php endif;?>
<?php if($isArchived&&$archiveAllowed):?>
<form method="post" action="quote-archive.php"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="quote_id" value="<?=$id?>"><button class="btn" name="action" value="restore">Arşivden Geri Yükle</button></form>
<?php endif;?>
<a class="btn secondary" href="<?=h($preview)?>">Önizle</a>
<?php if(!$isArchived&&!$protected&&$canShare):?><a class="btn secondary" href="quote.php?id=<?=$id?>">Düzenle</a><?php endif;?>
</div>
<?php if(!$isArchived&&$shareBlock):?><p><?=h($shareBlock)?></p><?php endif;?>
</section>
<?php if($canAccept):?>
<details class="panel qa-group" <?=$acceptError?'open':''?>>
<summary>Kabulü Kaydet</summary>
<form method="post" action="quote-actions.php?id=<?=$id?>">
<input type="hidden" name="csrf" value="<?=h(csrf())?>">
<p>Müşteri kabulünü kaydedin. Eksik sözleşme taslağı oluşturulur. Tarih belli ise taşınma planı da oluşturulur.</p>
<label>Yükleme tarihi (isteğe bağlı)
<input type="date" name="loading_date" value="<?=h($loadingDate??(ekDate($meta['move_date']??null)?$meta['move_date']:''))?>">
</label>
<button class="btn" type="submit" name="action" value="accept">Kabulü Kaydet</button>
</form>
</details>
<?php endif;?>
<div class="qa-groups">
<details class="panel qa-group"><summary>Belge ve Paylaşım</summary><ul>
<li><a href="v11-print.php?id=<?=$id?>&amp;lang=tr">Türkçe belge ve yazdırma</a></li>
<li><a href="v11-print.php?id=<?=$id?>&amp;lang=en">İngilizce belge ve yazdırma</a></li>
<?php if($canShare&&!empty($q['email'])):?><li><a href="v112-mail.php?id=<?=$id?>">Panelden E-posta Gönder</a></li><?php endif;?>
</ul></details>
<details class="panel qa-group"><summary>Taşınma ve Sözleşme</summary><ul>
<?php if($hasContract||!$isArchived):?><li><a href="v11-documents.php?id=<?=$id?>"><?=$hasContract?'Sözleşmeyi Aç':($accepted?'Sözleşme Taslağı Oluştur':'Sözleşme Çalışma Alanı')?></a></li><?php endif;?>
<?php if($accepted&&!$isArchived&&!$activeRevision&&$hasMove):?><li><a href="v11-calendar.php?edit=<?=$id?>">Taşınma Planını Düzenle</a></li><?php endif;?>
<?php if($isArchived&&!$hasContract):?><li>Bu kayda bağlı sözleşme bulunmuyor.</li><?php endif;?>
</ul></details>
<details class="panel qa-group"><summary>Tahsilat ve Geçmiş</summary><ul>
<li><a href="quote-payments.php?id=<?=$id?>">Bu Teklifin Tahsilatları</a></li>
<li><a href="v11-tracking.php?id=<?=$id?>">Gönderim Geçmişi</a></li>
<li><a href="quote-revisions.php?id=<?=$id?>">Revizyon Geçmişi</a></li>
<?php if($protected&&!$isArchived&&!$activeRevision&&$primary[0]!=='quote.php?id='.$id):?><li><a href="quote.php?id=<?=$id?>">Revizyon İşlemleri</a></li><?php endif;?>
</ul></details>
</div>
<?php if($archiveAllowed&&!$isArchived):?>
<details class="panel qa-group qa-archive"><summary>Diğer İşlemler</summary>
<form method="post" action="quote-archive.php" onsubmit="return confirm('Bu teklif arşivlensin mi? Daha sonra geri yükleyebilirsiniz.')">
<input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="quote_id" value="<?=$id?>">
<button class="btn secondary" name="action" value="archive">Arşivle</button>
</form></details>
<?php endif;bottom(); ?>
