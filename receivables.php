<?php
require __DIR__.'/inc/core.php';auth();require_once __DIR__.'/inc/permissions.php';ek_require_admin();
require __DIR__.'/inc/layout.php';require_once __DIR__.'/inc/revision-transition.php';
$pdo=db();$rows=[];$error='';
try {
 $rows=ekEffectiveFinancialRows($pdo);
} catch(Throwable $e){$error='Alacak raporu için tahsilat tablosu kurulmuş olmalıdır.';}
$currency=(string)($_GET['currency']??'');
top('Cari Alacaklar');
?>
<style>
.ek-fin{max-width:1300px;margin:auto}.ek-fin .panel{padding:24px;margin:20px 0;border-radius:16px}
.ek-fin table{width:100%;border-collapse:collapse}.ek-fin td,.ek-fin th{padding:12px;border-bottom:1px solid #e6ebf1;text-align:left}
.ek-fin th{font-size:12px;color:#526780}.ek-fin .amount{text-align:right;font-variant-numeric:tabular-nums}
.ek-fin .summary{display:flex;flex-wrap:wrap;gap:12px}.ek-fin .stat{background:#f2f6fb;padding:18px;border-radius:12px;min-width:170px}
@media(max-width:700px){.ek-fin .scroll{overflow:auto}}
</style>
<div class="ek-fin"><div class="panel"><h1>Müşteri Cari Alacakları</h1><p>Teklif tutarı ve kayıtlı tahsilatlar arasındaki fark. Para birimleri ayrı hesaplanır.</p>
<?php if($error):?><p role="alert"><?=h($error)?></p><?php else:
$totals=[];foreach($rows as $r){$c=$r['currency'];$balance=(int)round((float)$r['total']*100)-(int)$r['paid_cents'];if(!isset($totals[$c]))$totals[$c]=0;$totals[$c]+=$balance;}
?><div class="summary"><?php foreach($totals as $c=>$v):?><div class="stat"><small><?=h($c)?> açık bakiye</small><h2><?=money($v/100,$c)?></h2></div><?php endforeach;?></div>
<form method="get"><label>Para birimi <select name="currency"><option value="">Tümü</option><?php foreach(array_keys($totals) as $c):?><option value="<?=h($c)?>" <?=$currency===$c?'selected':''?>><?=h($c)?></option><?php endforeach;?></select></label><button type="submit">Filtrele</button></form>
<div class="scroll"><table><thead><tr><th>Teklif</th><th>Müşteri</th><th>Durum</th><th>Geçerlilik tarihi*</th><th class="amount">Teklif</th><th class="amount">Ödenen</th><th class="amount">Bakiye</th></tr></thead><tbody>
<?php foreach($rows as $r):if($currency!==''&&$r['currency']!==$currency)continue;$paid=(int)$r['paid_cents'];$total=(int)round((float)$r['total']*100);?><tr>
<td><a href="view.php?id=<?=(int)$r['id']?>"><?=h($r['quote_no'])?></a></td><td><?=h($r['name'])?></td><td><?=h(statusText($r['status']))?></td><td><?=h($r['valid_until']??'—')?></td>
<td class="amount"><?=money($total/100,$r['currency'])?></td><td class="amount"><?=money($paid/100,$r['currency'])?></td><td class="amount"><strong><?=money(($total-$paid)/100,$r['currency'])?></strong></td></tr><?php endforeach;?></tbody></table></div>
<p><small>*Teklif geçerlilik tarihi ödeme vadesi değildir. Bu rapor gecikmiş alacakları sınıflandırmaz; yalnızca fiyatlandırılmış, gönderilmiş ve onaylanmış teklifleri kapsar; ödeme vadesini göstermez.</small></p>
<?php endif;?></div></div><?php bottom();?>
