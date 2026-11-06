<?php
require __DIR__.'/inc/v11.php';auth();v11_require();require_once __DIR__.'/inc/moving-file.php';
$err='';$ok='';$p=db();
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify();try{
  $id=filter_var($_POST['quote_id']??null,FILTER_VALIDATE_INT);$version=$_POST['plan_version']??null;$action=$_POST['action']??'save';
  if(!$id||!is_string($version)||!is_string($action))throw new RuntimeException('Geçerli plan isteği gönderin.');
  ekSaveMovePlan($p,$id,$version,(int)$_SESSION['uid'],$action,$_POST);$ok=$action==='cancel'?'Plan iptal edildi; geçmiş, teklif ve müşteri kayıtları korundu.':'Mevcut taşınma planı kaydedildi.';
 }catch(Throwable $e){error_log('Move plan: '.$e->getMessage());$err=$e instanceof PDOException?'Plan kaydedilemedi. Mevcut kayıt korundu.':$e->getMessage();}
}
$p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->beginTransaction();
$month=$_GET['month']??date('Y-m');if(!is_string($month)||!preg_match('/^(19|20|21)[0-9]{2}-(0[1-9]|1[0-2])$/D',$month))$month=date('Y-m');
$from=$month.'-01';$to=date('Y-m-d',strtotime($from.' +1 month'));
$s=$p->prepare('SELECT m.* FROM v11_moves m WHERE loading_date>=? AND loading_date<? ORDER BY loading_date,id');$s->execute([$from,$to]);$moves=$s->fetchAll(PDO::FETCH_ASSOC);$rows=[];$rowErrors=[];
foreach($moves as $move){try{$ctx=ekMoveContext($p,(int)$move['quote_id']);$rows[]=$ctx;}catch(Throwable $e){$rowErrors[]='Plan #'.(int)$move['id'].': '.$e->getMessage();}}
$quotes=$p->query("SELECT q.id,q.quote_no,c.name FROM quotes q JOIN customers c ON c.id=q.customer_id WHERE NOT EXISTS(SELECT 1 FROM quote_archives qa WHERE qa.quote_id=q.id) AND NOT EXISTS(SELECT 1 FROM moving_quote_meta mm WHERE mm.quote_id=q.id AND (JSON_EXTRACT(mm.payload,'$.revision_effective_child_id')>0 OR (JSON_EXTRACT(mm.payload,'$.revision_parent_id')>0 AND JSON_EXTRACT(mm.payload,'$.revision_activated') IS NULL))) ORDER BY q.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
$selected=max(0,(int)($_POST['quote_id']??$_GET['edit']??($quotes[0]['id']??0)));$editing=null;
if($selected){try{$editing=ekMoveContext($p,$selected);$selected=$editing['effective_id'];}catch(Throwable $e){$err=$err?:$e->getMessage();}}
$p->commit();
require __DIR__.'/inc/layout.php';top('Taşınma Takvimi');
?>
<link rel="stylesheet" href="assets/v11.css?v=36001">
<div class="v11-head"><h1>Taşınma Takvimi</h1><p>Her taşınmanın mevcut planı korunur; güncel teklif revizyonu aynı dosyadan izlenir.</p><a href="moving-files.php">Taşınma dosyaları</a></div>
<?php if($err):?><p class="error" role="alert"><?=h($err)?></p><?php endif;?><?php if($ok):?><p class="notice" role="status"><?=h($ok)?></p><?php endif;?>
<details class="v11-card ek-summary" <?=isset($_GET['edit'])||$err?'open':''?>><summary>Taşınma Planla / Güncelle</summary>
<form method="get" class="v11-fields"><input type="hidden" name="month" value="<?=h($month)?>"><label>Geçerli teklif<select name="edit" required><?php foreach($quotes as $option):?><option value="<?=(int)$option['id']?>" <?=$selected===(int)$option['id']?'selected':''?>><?=h($option['quote_no'].' · '.$option['name'])?></option><?php endforeach;?></select></label><button type="submit">Dosyayı aç</button></form>
<?php if($editing):$plan=$editing['move']??[];?><p><a href="move-file.php?id=<?=(int)$editing['effective_id']?>">Taşınma dosyasını görüntüle</a> · <?=h($editing['quote']['quote_no'])?></p>
<form method="post" class="v11-fields"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="quote_id" value="<?=(int)$editing['effective_id']?>"><input type="hidden" name="plan_version" value="<?=h($editing['version'])?>"><input type="hidden" name="action" value="save">
<label>Yükleme tarihi<input type="date" name="loading_date" value="<?=h($plan['loading_date']??'')?>" required></label><label>Tahmini teslimat<input type="date" name="delivery_date" value="<?=h($plan['delivery_date']??'')?>"></label>
<label>Durum<select name="status"><?php foreach(['planned','loading','in_transit','delivered','cancelled'] as $status):?><option value="<?=$status?>" <?=($plan['status']??'planned')===$status?'selected':''?>><?=h(ekMoveStatus($status))?></option><?php endforeach;?></select></label>
<label>Operasyon notu<textarea name="notes" maxlength="3000" rows="3"><?=h($plan['notes']??'')?></textarea></label><button type="submit">Planı Kaydet</button></form><?php endif;?></details>
<div class="v11-card"><form method="get" class="v11-fields"><label>Ay seç<input type="month" name="month" value="<?=h($month)?>"></label><button type="submit">Göster</button></form><h2><?=h(date('m.Y',strtotime($from)))?> Taşınma Planı</h2>
<?php foreach($rowErrors as $message):?><p role="alert"><?=h($message)?> Kayıt otomatik silinmedi veya birleştirilmedi.</p><?php endforeach;?>
<div class="v11-table ek-row-actions" role="region" aria-label="Taşınma takvimi" tabindex="0"><table><thead><tr><th>Yükleme</th><th>Teslimat</th><th>Geçerli teklif / dosya</th><th>Müşteri</th><th>Durum</th><th>İşlemler</th></tr></thead><tbody>
<?php foreach($rows as $ctx):$plan=$ctx['move'];?><tr><td><?=h(v11_date($plan['loading_date']))?></td><td><?=h(v11_date($plan['delivery_date']))?></td><td><a href="move-file.php?id=<?=(int)$ctx['effective_id']?>"><?=h($ctx['quote']['quote_no'])?></a><div>TD-<?=str_pad((string)$ctx['root_id'],6,'0',STR_PAD_LEFT)?></div></td><td><?=h($ctx['quote']['name'])?></td><td><?=h(ekMoveStatus($plan['status']))?></td><td><a href="v11-calendar.php?<?=h(http_build_query(['month'=>$month,'edit'=>$ctx['effective_id']]))?>">Düzenle</a>
<?php if($plan['status']!=='cancelled'):?><form method="post" class="v11-delete-form" onsubmit="return confirm('Plan iptal edilsin mi? Geçmiş ve teklif kaydı korunur.');"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="quote_id" value="<?=(int)$ctx['effective_id']?>"><input type="hidden" name="plan_version" value="<?=h($ctx['version'])?>"><button type="submit" name="action" value="cancel">İptal et</button></form><?php endif;?></td></tr><?php endforeach;?>
<?php if(!$rows&&!$rowErrors):?><tr><td colspan="6">Seçili ayda taşınma planı bulunmuyor.</td></tr><?php endif;?></tbody></table></div></div><?php bottom(); ?>
