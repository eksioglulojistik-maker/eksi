<?php
require __DIR__.'/inc/core.php';auth();require __DIR__.'/inc/layout.php';
$pdo=db();$id=max(0,(int)($_GET['id']??0));
$s=$pdo->prepare('SELECT q.*,c.name AS customer_name FROM quotes q JOIN customers c ON c.id=q.customer_id WHERE q.id=?');
$s->execute([$id]);$q=$s->fetch();if(!$q){http_response_code(404);exit('Teklif bulunamadı.');}
$rows=[];$error='';
try{$s=$pdo->prepare('SELECT id,revision_no,actor_id,created_at,snapshot FROM v11_quote_revisions WHERE quote_id=? ORDER BY revision_no DESC');$s->execute([$id]);$rows=$s->fetchAll();}
catch(Throwable $e){$error='Revizyon tablosu eksik. Ayarlar → Sistem Kontrolü bölümünü inceleyin.';}
$selected=max(0,(int)($_GET['revision']??0));$data=null;
foreach($rows as $row)if((int)$row['id']===$selected){$data=json_decode($row['snapshot'],true);break;}
top('Teklif Revizyon Geçmişi');
?>
<style>.ek-fin{max-width:1150px;margin:auto}.ek-fin .panel{margin:16px 0;padding:22px;border-radius:14px}.ek-fin table{width:100%;border-collapse:collapse}.ek-fin th,.ek-fin td{text-align:left;padding:12px;border-bottom:1px solid #e6eaf0}.ek-fin .muted{color:#65758b}.ek-fin .tags{display:flex;gap:10px;flex-wrap:wrap}.ek-fin .tags a{background:#eaf0f8;padding:9px 13px;border-radius:8px;text-decoration:none;color:#173d70}</style>
<div class="ek-fin"><div class="heading"><div><h1>Teklif Revizyon Geçmişi</h1><p><?=h($q['quote_no'])?> · <?=h($q['customer_name'])?></p></div><a class="btn secondary" href="view.php?id=<?=$id?>">Teklife Dön</a></div>
<?php if($error):?><p class="error"><?=h($error)?></p><?php endif;?>
<div class="panel"><h2>Kaydedilmiş sürümler</h2><p class="muted">Bu ekran müşteriye hangi sürümün gönderildiğini doğrulamaz. Teklif her düzenlendiğinde değişiklikten önceki tam kayıt saklanır. Bu sürümler yalnızca görüntülenebilir; geçmiş kayıtlar değiştirilmez.</p>
<table><thead><tr><th>Sürüm</th><th>Kaydedilme zamanı</th><th>İşlem</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td>V<?=h($r['revision_no'])?></td><td><?=h($r['created_at'])?></td><td><a href="?id=<?=$id?>&revision=<?=(int)$r['id']?>">Görüntüle</a></td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="3">Henüz revizyon yok. İlk düzenlemede önceki sürüm saklanacak.</td></tr><?php endif;?></tbody></table></div>
<?php if($data):$old=$data['quote']??[];$details=$data['details']??[];?>
<div class="panel"><h2>Arşivlenen teklif — <?=h($old['quote_no']??'')?></h2>
<p><strong>Tutar:</strong> <?=money($old['total']??0,$old['currency']??'EUR')?> · <strong>Taşıma:</strong> <?=h($old['mode']??'')?></p>
<p><strong>Güzergâh:</strong> <?=h($old['origin']??'')?> → <?=h($old['destination']??'')?></p>
<p><strong>Hacim:</strong> <?=h(number_format((float)($old['volume']??0),2,',','.'))?> m³ · <strong>Durum:</strong> <?=h(statusText($old['status']??''))?></p>
<?php require_once __DIR__.'/inc/quote-richtext.php';
foreach(['service_scope'=>'Hizmetlerimiz','payment_terms'=>'Ödeme Şekli','delivery_time'=>'Teslimat Süresi','additional_terms'=>'Diğer Hususlar'] as $key=>$label):?>
<h3><?=h($label)?></h3><div><?=ekRichDisplay($details[$key]??'')?></div>
<?php endforeach;?><button type="button" onclick="window.print()">Arşiv Kaydını Yazdır (tarayıcı PDF)</button><p class="muted">Bu çıktı standart teklif PDF şablonu değildir; geçmiş sürümün arşiv görünümüdür.</p></div><?php endif;?></div>
<?php bottom(); ?>
