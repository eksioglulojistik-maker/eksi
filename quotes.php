<?php
require __DIR__.'/inc/core.php';
auth();
require __DIR__.'/inc/layout.php';
require_once __DIR__.'/inc/revision-transition.php';$pdo=db();
$archiveReady=false;
try{$archiveReady=(bool)$pdo->query("SHOW TABLES LIKE 'quote_archives'")->fetchColumn();}catch(Throwable $e){}
$archived=$archiveReady && (($_GET['archived']??'')==='1');
$q=trim((string)($_GET['q']??''));
$status=(string)($_GET['status']??'');
$allowed=['draft','priced','sent','accepted','rejected','expired','pending'];
if(!in_array($status,$allowed,true))$status='';
$where=[];$params=[];$customerId=max(0,(int)($_GET['customer_id']??0));if($customerId){$where[]='q.customer_id=?';$params[]=$customerId;}
if($q!==''){$where[]='(q.quote_no LIKE ? OR c.name LIKE ? OR c.email LIKE ? OR q.origin LIKE ? OR q.destination LIKE ?)';$params=array_merge($params,array_fill(0,5,'%'.$q.'%'));}
if($status==='pending')$where[]="q.status IN ('draft','priced','sent')";elseif($status!==''){$where[]='q.status=?';$params[]=$status;}
if($archiveReady)$where[]=($archived?'EXISTS':'NOT EXISTS').' (SELECT 1 FROM quote_archives qa WHERE qa.quote_id=q.id)';
if($status==='accepted')$where[]="NOT EXISTS(SELECT 1 FROM moving_quote_meta mm WHERE mm.quote_id=q.id AND JSON_EXTRACT(mm.payload,'$.revision_effective_child_id')>0)";
$whereSql=$where?' WHERE '.implode(' AND ',$where):'';
$page=max(1,(int)($_GET['page']??1));$pageSize=50;$countStmt=$pdo->prepare('SELECT COUNT(*) FROM quotes q JOIN customers c ON c.id=q.customer_id'.$whereSql);$countStmt->execute($params);$recordCount=(int)$countStmt->fetchColumn();$pageCount=max(1,(int)ceil($recordCount/$pageSize));$page=min($page,$pageCount);$offset=($page-1)*$pageSize;
$sql='SELECT q.id,q.quote_no,q.created_at,q.mode,q.origin,q.destination,q.volume,q.total,q.currency,q.status,c.id AS customer_profile_id,c.name,c.email,c.phone FROM quotes q JOIN customers c ON c.id=q.customer_id'.$whereSql.' ORDER BY q.id DESC LIMIT '.$pageSize.' OFFSET '.$offset;
$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
// Summary metrics use all active quotes, independent of the current filter.
$base=' FROM quotes q';
if($archiveReady)$base.=' WHERE NOT EXISTS (SELECT 1 FROM quote_archives qa WHERE qa.quote_id=q.id)';
$base.=($archiveReady?' AND ':' WHERE ')."NOT EXISTS(SELECT 1 FROM moving_quote_meta mm WHERE mm.quote_id=q.id AND JSON_EXTRACT(mm.payload,'$.revision_effective_child_id')>0)";
$totals=$pdo->query('SELECT COUNT(*) total_count, SUM(CASE WHEN q.status=\'accepted\' THEN 1 ELSE 0 END) accepted_count, SUM(CASE WHEN q.status IN (\'draft\',\'priced\') THEN 1 ELSE 0 END) pending_count, SUM(CASE WHEN q.status=\'sent\' THEN 1 ELSE 0 END) sent_count, SUM(CASE WHEN DATE(q.created_at)=CURDATE() THEN 1 ELSE 0 END) today_count'.$base)->fetch(PDO::FETCH_ASSOC)?:[];
$summary=[(int)($totals['accepted_count']??0),(int)($totals['pending_count']??0),(int)($totals['sent_count']??0),(int)($totals['today_count']??0),(int)($totals['total_count']??0)];
function quoteModeLabel($value){$v=mb_strtolower(trim((string)$value),'UTF-8');if($v==='sea_fcl')return 'Denizyolu FCL';if($v==='sea_lcl')return 'Denizyolu LCL';if($v==='road'||$v==='karayolu')return 'Karayolu';if(strpos($v,'deniz')!==false)return 'Denizyolu';if(strpos($v,'kara')!==false)return 'Karayolu';return 'Belirtilmedi';}
function quoteCurrency($value){$v=strtoupper(trim((string)$value));return in_array($v,['EUR','USD','TRY','GBP'],true)?$v:'EUR';}
function quoteMoney($value,$currency){return number_format((float)$value,2,',','.').' '.quoteCurrency($currency);}
$sumByCurrency=[];
try{$sumSql='SELECT q.currency,COALESCE(SUM(q.total),0) amount'.$base.' GROUP BY q.currency';foreach($pdo->query($sumSql)->fetchAll(PDO::FETCH_ASSOC) as $item){$sumByCurrency[]=quoteMoney($item['amount'],$item['currency']);}}catch(Throwable $e){}
top('Teklifler');
?>
<link rel="stylesheet" href="assets/v1223-quotes.css?v=364201">
<div class="eq-page">
  <div class="eq-heading"><div><div class="eq-eyebrow">TEKLİF YÖNETİMİ / INTERNATIONAL MOVERS</div><h1><?= $archived?'Arşivlenmiş Teklifler':'Teklifler' ?></h1><p>Teklifleri, müşterileri ve sözleşme süreçlerini tek merkezden takip edin.</p></div><div class="eq-top-actions"><a class="eq-btn eq-btn-outline" href="quotes.php<?= $archived?'':'?archived=1' ?>"><?= $archived?'← Aktif Teklifler':'Arşivlenmiş Teklifler' ?></a><?php if(!$archived):?><a class="eq-btn eq-btn-primary" href="quote.php">＋ Yeni Teklif</a><?php endif;?></div></div>
  <?php if(!$archived):?>
  <details class="ek-summary"><summary>Aktif teklif özeti · <?=number_format($summary[4],0,',','.')?> teklif</summary><div class="eq-metrics">
  <?php $metricItems=[['Onaylanan teklifler',$summary[0],'✓','blue'],['Fiyatlandırma bekleyen',$summary[1],'◷','blue'],['Gönderilen teklifler',$summary[2],'➤','green'],['Bugün oluşturulan',$summary[3],'▣','orange'],['Toplam teklifler',$summary[4],'▥','slate']];foreach($metricItems as $m):?>
    <div class="eq-metric eq-<?=h($m[3])?>"><div class="eq-metric-icon"><?=h($m[2])?></div><span><?=h($m[0])?></span><strong><?=number_format($m[1],0,',','.')?></strong><small><?= $m[3]==='slate'?'Kayıtlı aktif teklifler':'Teklif durumuna göre' ?></small></div>
  <?php endforeach;?></div>
  <?php if($sumByCurrency):?><div class="eq-value-line"><b>Toplam teklif tutarları</b><span><?=implode(' <span class="eq-separator">·</span> ',$sumByCurrency)?></span><small>Para birimleri ayrı hesaplanır.</small></div><?php endif;?>
  </details><?php endif;?>
  <form class="eq-filters" method="get" action="quotes.php">
    <?php if($customerId):?><input type="hidden" name="customer_id" value="<?=$customerId?>"><?php endif;?><?php if($archived):?><input type="hidden" name="archived" value="1"><?php endif;?>
    <label><span>Durum filtresi</span><select name="status"><option value="">Tüm durumlar</option><?php foreach($allowed as $s):?><option value="<?=h($s)?>" <?= $status===$s?'selected':'' ?>><?=h(statusText($s))?></option><?php endforeach;?></select></label>
    <label class="eq-filter-search"><span>Arama</span><input type="search" name="q" value="<?=h($q)?>" placeholder="Teklif no, müşteri, e-posta veya güzergâh..."></label>
    <button type="submit" class="eq-btn eq-btn-primary">⌕ Filtrele</button><a class="eq-btn eq-btn-outline" href="quotes.php<?= $archived?'?archived=1':'' ?>">Temizle</a>
  </form>
  <nav class="eq-quick-tabs" aria-label="Hızlı teklif filtreleri"><a href="quotes.php" class="<?=(!$archived && $status==='')?'is-active':''?>">Tüm teklifler <span><?=number_format($summary[4],0,',','.')?></span></a><a href="quotes.php?status=pending" class="<?=(!$archived && $status==='pending')?'is-active':''?>">İşlem bekleyen</a><a href="quotes.php?status=accepted" class="<?=(!$archived && $status==='accepted')?'is-active':''?>">Onaylanan</a><a href="quotes.php?archived=1" class="<?=$archived?'is-active':''?>">Arşiv</a></nav><section class="eq-list"><div class="eq-list-title"><div><h2><?= $archived?'Arşivlenmiş teklifler':'Teklif kayıtları' ?></h2><p><?=count($rows)?> / <?=$recordCount?> kayıt · Sayfa <?=$page?> / <?=$pageCount?>.</p></div><span class="eq-list-label">EKŞİOĞLU LOJİSTİK</span></div>
  <?php if(isset($_GET['purged'])):?><p role="status">Teklif ve bağlı taslak bilgileri kalıcı silindi.</p><?php endif;?>
  <div class="eq-table-wrap" role="region" aria-label="Teklifler" tabindex="0"><table class="eq-table" role="table"><thead><tr><th>Teklif No</th><th>Müşteri</th><th>Taşıma Tipi</th><th>Güzergâh</th><th>Hacim</th><th>Fiyat ve Durum</th><th>İşlemler</th></tr></thead><tbody role="rowgroup">
  <?php foreach($rows as $r):$id=(int)$r['id'];$no=h($r['quote_no']);$stt=(string)$r['status'];$rowMeta=ekRevisionMeta($pdo,$id);?>
    <tr class="eq-quote-row" role="row"><td class="eq-quote-id" role="cell"><a href="view.php?id=<?=$id?>"><?=$no?></a><small><?=h(date('d.m.Y',strtotime($r['created_at'])))?></small></td>
    <td class="eq-customer" role="cell"><a class="eq-customer-link" href="customer-detail.php?id=<?=(int)$r['customer_profile_id']?>"><?=h($r['name'])?></a><?php if($r['email']):?><small><?=h($r['email'])?></small><?php endif;?><?php if($r['phone']):?><small><?=h($r['phone'])?></small><?php endif;?></td>
    <td class="eq-mode-cell" role="cell"><span class="eq-mode"><?=strpos(quoteModeLabel($r['mode']),'Denizyolu')===0?'⚓':'▣'?> <?=h(quoteModeLabel($r['mode']))?></span></td>
    <td class="eq-route" role="cell"><span class="eq-mobile-label">Güzergâh</span><span><?=h($r['origin']?:'—')?></span><i>↓</i><span><?=h($r['destination']?:'—')?></span></td>
    <td class="eq-volume-cell" role="cell"><span class="eq-mobile-label">Hacim</span><span class="eq-volume"><?=number_format((float)$r['volume'],2,',','.')?> m³</span></td>
    <td class="eq-price-cell" role="cell"><span class="eq-mobile-label">Fiyat ve durum</span><b class="eq-price"><?=quoteMoney($r['total'],$r['currency'])?></b><span class="eq-status eq-status-<?=h($stt)?>"><?=h(statusText($stt))?></span><?php if(!empty($rowMeta['revision_effective_child_id'])):?><small>Tarihsel kaynak · <a href="view.php?id=<?=(int)$rowMeta['revision_effective_child_id']?>">Geçerli revizyon</a></small><?php elseif(!empty($rowMeta['revision_activated'])):?><small>Geçerli revizyon</small><?php endif;?></td>
    <td class="eq-actions-cell" role="cell"><div class="eq-actions">
<a class="eq-action eq-view" href="view.php?id=<?=$id?>" title="Önizle">◉ <span>Önizle</span></a>
<?php if(!$archived):?><a class="eq-action eq-edit" href="quote.php?id=<?=$id?>" title="Düzenle">✎ <span>Düzenle</span></a><?php endif;?>
<?php if(!$archived||$archiveReady||ekAdmin()):?>
<details class="eq-secondary" open><summary class="eq-action">Diğer İşlemler</summary><div class="eq-secondary-actions">
<?php if(!$archived):?><a class="eq-action eq-contract" href="v11-documents.php?id=<?=$id?>" title="Sözleşme yönetimi">▤ <span>Sözleşme</span></a><?php endif;?>
<?php if($archiveReady):?><form method="post" action="quote-archive.php" onsubmit="return confirm('<?= $archived?'Bu teklif aktif listeye geri yüklensin mi?':'Bu teklif arşivlensin mi? Geri yüklenebilir.' ?>');"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="quote_id" value="<?=$id?>"><input type="hidden" name="action" value="<?=$archived?'restore':'archive'?>"><button class="eq-action <?=$archived?'eq-restore':'eq-delete'?>" type="submit" title="<?=$archived?'Geri yükle':'Arşivle'?>"><?=$archived?'↶':'♲'?> <span><?=$archived?'Geri yükle':'Arşivle'?></span></button></form><?php endif;?>
<?php if(ekAdmin()):?><a class="eq-action" href="permanent-delete.php?kind=quote&amp;id=<?=$id?>">Sil</a><?php endif;?>
</div></details><?php endif;?>
</div></td></tr>
  <?php endforeach;?><?php if(!$rows):?><tr role="row"><td colspan="7" class="eq-empty" role="cell">Bu filtreye uygun teklif bulunamadı.</td></tr><?php endif;?></tbody></table></div>
  <?php if($pageCount>1):?><nav class="eq-top-actions" aria-label="Teklif sayfaları"><?php foreach(['Önceki'=>$page-1,'Sonraki'=>$page+1] as $label=>$target):if($target<1||$target>$pageCount)continue;?><a class="eq-btn eq-btn-outline" href="<?=h('quotes.php?'.http_build_query(['page'=>$target,'q'=>$q,'status'=>$status,'customer_id'=>$customerId,'archived'=>$archived?1:0]))?>"><?=h($label)?></a><?php endforeach;?></nav><?php endif;?>
  </section>
</div>
<script>
(() => {
  const mobile = window.matchMedia('(max-width: 850px)');
  const menus = document.querySelectorAll('.eq-secondary');
  const apply = () => menus.forEach(menu => { menu.open = !mobile.matches; });
  apply();
  mobile.addEventListener('change', apply);
})();
</script>
<?php bottom();
