<?php
require __DIR__.'/inc/core.php';auth();require __DIR__.'/inc/layout.php';
require_once __DIR__.'/inc/revision-transition.php';
$pdo=db();$error='';$ready=false;$rows=[];$totals=[];$balances=[];$count=0;
$search=trim((string)($_GET['q']??''));$search=mb_substr($search,0,100);
$currency=(string)($_GET['currency']??'');if(!in_array($currency,['','EUR','USD','TRY','GBP'],true))$currency='';
$method=(string)($_GET['method']??'');if(!in_array($method,['','bank','cash','card','other'],true))$method='';
$from=(string)($_GET['from']??'');$to=(string)($_GET['to']??'');
foreach(['from','to'] as $key){$v=$$key;if($v!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v)||!checkdate((int)substr($v,5,2),(int)substr($v,8,2),(int)substr($v,0,4))))$$key='';}
$includeTest=isset($_GET['test'])&&$_GET['test']==='1';$page=max(1,min(100000,(int)($_GET['page']??1)));$perPage=25;
$where=[];$params=[];
if(!$includeTest)$where[]="c.name NOT LIKE '[TEST]%'";
if($search!==''){$where[]='(c.name LIKE ? OR q.quote_no LIKE ? OR p.note LIKE ?)';$term='%'.$search.'%';array_push($params,$term,$term,$term);}
if($currency!==''){$where[]='p.currency=?';$params[]=$currency;}
if($method!==''){$where[]='p.method=?';$params[]=$method;}
if($from!==''){$where[]='p.paid_at>=?';$params[]=$from;}
if($to!==''){$where[]='p.paid_at<=?';$params[]=$to;}
$clause=$where?' WHERE '.implode(' AND ',$where):'';
try{
 $ready=(bool)$pdo->query("SHOW TABLES LIKE 'ek_quote_payments'")->fetchColumn();
 if($ready){
  $base=' FROM ek_quote_payments p JOIN quotes q ON q.id=p.quote_id JOIN customers c ON c.id=q.customer_id';
  $s=$pdo->prepare('SELECT COUNT(*)'.$base.$clause);$s->execute($params);$count=(int)$s->fetchColumn();
  $s=$pdo->prepare('SELECT p.currency,SUM(p.amount_cents) AS cents'.$base.$clause.' GROUP BY p.currency ORDER BY p.currency');$s->execute($params);$totals=$s->fetchAll();
  $page=min($page,max(1,(int)ceil($count/$perPage)));$offset=($page-1)*$perPage;
  $s=$pdo->prepare('SELECT p.id,p.quote_id,p.amount_cents,p.currency,p.paid_at,p.method,p.note,p.created_by,p.created_at,q.quote_no,c.name AS customer_name'.$base.$clause.' ORDER BY p.paid_at DESC,p.id DESC LIMIT '.$perPage.' OFFSET '.$offset);
  $s->execute($params);$rows=$s->fetchAll();
  $balanceMap=[];
  foreach(ekEffectiveFinancialRows($pdo,$includeTest) as $financialRow){
   $cur=$financialRow['currency'];if(!isset($balanceMap[$cur]))$balanceMap[$cur]=['currency'=>$cur,'billed'=>0,'collected'=>0,'quotes_count'=>0];
   $balanceMap[$cur]['billed']+=ekRevisionCents((string)$financialRow['total']);$balanceMap[$cur]['collected']+=(int)$financialRow['paid_cents'];$balanceMap[$cur]['quotes_count']++;
  }
  foreach($balanceMap as $summary){$summary['billed']/=100;$summary['collected']/=100;$balances[]=$summary;}

 }
}catch(Throwable $e){$error='Tahsilat kayıtları okunamadı. Veritabanı ve tablo yapısını kontrol edin.';error_log('EK payments dashboard: '.$e->getMessage());}
$methods=['bank'=>'Havale / EFT','cash'=>'Nakit','card'=>'Kart','other'=>'Diğer'];
$query=$_GET;unset($query['page']);$qs=http_build_query($query);$baseUrl='payments.php?'.($qs!==''?$qs.'&':'');
top('Tahsilat Yönetimi');
?>
<style>
.ek2{max-width:1370px;margin:auto;color:#172e4c}.ek2 *{box-sizing:border-box}.ek2 .ek2-header{display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap;margin:10px 0 22px}.ek2 h1{margin:0;color:#102c4e}.ek2 .sub{color:#475569;margin:7px 0}.ek2 .btn-primary{background:#1c4c86;color:#fff!important;padding:12px 17px;border-radius:9px;text-decoration:none;font-weight:700}.ek2 .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin-bottom:22px}.ek2 .stat{background:white;border:1px solid #dce5ef;border-radius:14px;padding:20px;border-top:4px solid #234c7c}.ek2 .stat .label{font-size:12px;color:#64788e}.ek2 .stat strong{display:block;font-size:22px;margin-top:9px;color:#173c6c;overflow-wrap:anywhere}.ek2 .stat small{display:block;color:#66778b;margin-top:8px}.ek2 .panel{background:white;border:1px solid #dce5ef;border-radius:14px;padding:22px;margin:18px 0;box-shadow:0 4px 18px #15355d08}.ek2 .filters{display:grid;grid-template-columns:2fr repeat(4,minmax(125px,1fr));gap:12px;align-items:end}.ek2 label{font-size:12px;color:#526984;font-weight:650}.ek2 input,.ek2 select{display:block;width:100%;margin-top:6px;min-height:40px;border:1px solid #cfdbe8;border-radius:8px;padding:9px;background:#fff;color:#183454}.ek2 .filter-actions{display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-top:13px}.ek2 button{border:0;background:#1b4b84;color:white;padding:11px 17px;border-radius:8px;cursor:pointer}.ek2 a{color:#1b4b84}.ek2 .tablewrap{overflow:auto}.ek2 table{border-collapse:collapse;width:100%;min-width:950px}.ek2 th{text-align:left;background:#f3f6fa;color:#5c7089;font-size:12px}.ek2 td,.ek2 th{padding:13px;border-bottom:1px solid #e7edf4;vertical-align:middle}.ek2 .amount{text-align:right;white-space:nowrap;font-weight:700}.ek2 .muted{color:#475569}.ek2 .tag{display:inline-block;padding:5px 9px;border-radius:6px;background:#eaf0f7;font-size:12px;color:#234b78}.ek2 .pagination{display:flex;justify-content:space-between;gap:15px;align-items:center;margin-top:18px}.ek2 .pagination a{padding:9px 14px;border:1px solid #d4dfeb;border-radius:8px;text-decoration:none}.ek2 .notice{padding:12px 15px;border-radius:8px;background:#eff6ff;color:#1e40af;margin-bottom:12px}@media(max-width:1050px){.ek2 .filters{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.ek2 .filters{grid-template-columns:1fr}.ek2 .panel{padding:15px}}
</style>
<div class="ek2"><div class="ek2-header"><div><h1>Tahsilat Yönetimi</h1><p class="sub">Ödemeler, müşteri kayıtları, makbuzlar ve alacak takibi</p></div><a class="btn-primary" href="quotes.php">+ Teklif Seç / Tahsilat Ekle</a></div>
<?php if(!$ready):?><div class="notice">Tahsilat tablosu bulunamadı. Sistem Kontrolü bölümünden yardımcı tabloları oluşturun.</div><?php endif;?>
<?php if($error):?><div class="notice"><?=h($error)?></div><?php endif;?>
<details class="ek-summary"><summary>Tahsilat ve teklif bakiyesi özetleri</summary><div class="cards">
<?php foreach($totals as $t):?><div class="stat"><span class="label"><?=h($t['currency'])?> · Filtrelenmiş Tahsilat</span><strong><?=money($t['cents']/100,$t['currency'])?></strong><small>Seçili filtrelerdeki ödeme toplamı</small></div><?php endforeach;?>
<?php if(!$totals):?><div class="stat"><span class="label">Tahsilat</span><strong>—</strong><small>Filtreye uygun kayıt bulunamadı</small></div><?php endif;?>
<?php foreach($balances as $b):?><div class="stat"><span class="label"><?=h($b['currency'])?> · Teklif Bakiyesi</span><strong><?=money(max(0,(float)$b['billed']-(float)$b['collected']),$b['currency'])?></strong><small>Gönderilen, onaylanan veya fiyatlandırılmış <?= (int)$b['quotes_count']?> teklifin toplamından</small></div><?php endforeach;?>
</div></details>
<details class="panel ek-summary" <?=($search!==''||$currency!==''||$method!==''||$from!==''||$to!==''||$includeTest)?'open':''?>><summary>Tahsilat filtreleri</summary><form method="get"><div class="filters"><label>Müşteri, teklif veya açıklama<input name="q" value="<?=h($search)?>" placeholder="Ara..."></label><label>Para Birimi<select name="currency"><option value="">Tümü</option><?php foreach(['EUR','USD','TRY','GBP'] as $v):?><option value="<?=$v?>" <?=$currency===$v?'selected':''?>><?=$v?></option><?php endforeach;?></select></label><label>Ödeme Yöntemi<select name="method"><option value="">Tümü</option><?php foreach($methods as $v=>$label):?><option value="<?=h($v)?>" <?=$method===$v?'selected':''?>><?=h($label)?></option><?php endforeach;?></select></label><label>Başlangıç<input type="date" name="from" value="<?=h($from)?>"></label><label>Bitiş<input type="date" name="to" value="<?=h($to)?>"></label></div><div class="filter-actions"><label><input type="checkbox" name="test" value="1" <?=$includeTest?'checked':''?> style="width:auto;min-height:auto;display:inline;margin:0 5px 0 0">Test kayıtlarını göster</label><button type="submit">Filtrele</button><a href="payments.php">Temizle</a></div></form></details>
<div class="panel"><div class="ek2-header"><div><h2 style="margin:0">Tahsilat Kayıtları</h2><p class="sub"><?=number_format($count,0,',','.')?> kayıt · Sayfa <?=$page?> / <?=max(1,(int)ceil($count/$perPage))?></p></div><span class="tag">Para birimleri ayrı hesaplanır</span></div>
<div class="tablewrap"><table><thead><tr><th>Makbuz No</th><th>Ödeme Tarihi</th><th>Teklif / Müşteri</th><th class="amount">Tutar</th><th>Yöntem</th><th>Açıklama</th><th>Kaydeden</th><th>İşlemler</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><strong><?=h('EK-M-'.str_pad((string)$r['id'],6,'0',STR_PAD_LEFT))?></strong></td><td><?=h(date('d.m.Y',strtotime($r['paid_at'])))?></td><td><a href="quote-payments.php?id=<?=(int)$r['quote_id']?>"><?=h($r['quote_no'])?></a><div class="muted"><?=h($r['customer_name'])?></div></td><td class="amount"><?=money($r['amount_cents']/100,$r['currency'])?></td><td><span class="tag"><?=h($methods[$r['method']]??$r['method'])?></span></td><td><?=h($r['note'])?></td><td>#<?=(int)$r['created_by']?></td><td><a href="payment-receipt.php?id=<?=(int)$r['id']?>" target="_blank" rel="noopener">Makbuz / PDF</a></td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="8" class="muted">Bu filtrelerle eşleşen tahsilat bulunamadı.</td></tr><?php endif;?></tbody></table></div>
<div class="pagination"><span class="muted">Her sayfada <?=$perPage?> kayıt</span><div><?php if($page>1):?><a href="<?=h($baseUrl.'page='.($page-1))?>">← Önceki</a><?php endif;?> <?php if($page*$perPage<$count):?><a href="<?=h($baseUrl.'page='.($page+1))?>">Sonraki →</a><?php endif;?></div></div></div>
<div class="notice">Bilgi: Kalan alacak kartları yalnızca en az bir tahsilatı olan teklifleri kapsar. Vadesi geçmiş alacak hesaplaması için ödeme planı ve vade verileri ayrıca gereklidir. Makbuz bağlantısı mevcut yazdır/PDF kaydet sayfasını açar.</div>
</div><?php bottom(); ?>
