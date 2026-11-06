<?php
// Definitions and table name are supplied by trusted PHP entry points only.
$pdo=db();$error='';$id=max(0,(int)($_GET['edit']??$_POST['id']??0));$row=[];
if($id){$s=$pdo->prepare('SELECT * FROM `'.$catalogTable.'` WHERE id=?');$s->execute([$id]);$row=$s->fetch()?:[];if(!$row){http_response_code(404);exit('Kayıt bulunamadı.');}}
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify();try{
  $action=$_POST['action']??'save';
  if(in_array($action,['archive','restore'],true)){
   if(!$id)throw new RuntimeException('Geçersiz kayıt.');$s=$pdo->prepare('UPDATE `'.$catalogTable.'` SET active=? WHERE id=?');$s->execute([$action==='restore'?1:0,$id]);
  }elseif($action==='save'){
   $values=[];
   foreach($catalogFields as $key=>$field){
    $v=trim((string)($_POST[$key]??''));
    if($field['required']&&$v==='')throw new RuntimeException($field['label'].' zorunludur.');
    if(mb_strlen($v)>($field['max']??190))throw new RuntimeException($field['label'].' çok uzun.');
    if(isset($field['options'])&&!array_key_exists($v,$field['options']))throw new RuntimeException($field['label'].' geçersiz.');
    if($field['type']==='number')$v=ekNumber($v?:0,$field['label']);
    $values[]=$v;
   }
   $keys=array_keys($catalogFields);
   if($id){$set=implode(',',array_map(fn($key)=>'`'.$key.'`=?',$keys));$sql='UPDATE `'.$catalogTable.'` SET '.$set.' WHERE id=?';$values[]=$id;}
   else{$sql='INSERT INTO `'.$catalogTable.'` (`'.implode('`,`',$keys).'`) VALUES ('.implode(',',array_fill(0,count($keys),'?')).')';}
   $pdo->prepare($sql)->execute($values);
  }else throw new RuntimeException('Geçersiz işlem.');
  redirect($catalogPath.'?saved=1');
 }catch(Throwable $e){$error=$e instanceof PDOException?'Kayıt tamamlanamadı. Veritabanı yapısını kontrol edin.':$e->getMessage();$row=array_merge($row,$_POST);}
}
$rows=$pdo->query('SELECT * FROM `'.$catalogTable.'` ORDER BY active DESC,id DESC')->fetchAll();
require __DIR__.'/layout.php';top($catalogTitle);
?><div class="heading"><div><h1><?=h($catalogTitle)?></h1><p><?=h($catalogDescription)?></p></div><a class="btn secondary" href="settings-hub.php">Ayarlara dön</a></div>
<?php if($error):?><p class="error"><?=h($error)?></p><?php endif;if(isset($_GET['saved'])):?><p class="notice">İşlem kaydedildi.</p><?php endif;?>
<div class="two"><section class="panel"><h2><?=$id?'Kaydı düzenle':'Yeni kayıt'?></h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="save">
<?php foreach($catalogFields as $key=>$field):?><label><?=h($field['label'])?>
<?php if(isset($field['options'])):?><select name="<?=h($key)?>"><?php foreach($field['options'] as $value=>$label):?><option value="<?=h($value)?>" <?=($row[$key]??$field['default']??'')===(string)$value?'selected':''?>><?=h($label)?></option><?php endforeach;?></select>
<?php elseif($field['type']==='textarea'):?><textarea name="<?=h($key)?>" rows="7" maxlength="<?=(int)($field['max']??8000)?>" <?=$field['required']?'required':''?>><?=h($row[$key]??'')?></textarea>
<?php else:?><input type="<?=h($field['type'])?>" name="<?=h($key)?>" value="<?=h($row[$key]??$field['default']??'')?>" maxlength="<?=(int)($field['max']??190)?>" <?=$field['required']?'required':''?> <?=$field['type']==='number'?'min="0" step="0.01"':''?>><?php endif;?></label><?php endforeach;?>
<button>Kaydet</button> <a href="<?=h($catalogPath)?>">Yeni kayıt / İptal</a></form></section><section class="panel"><h2>Kayıtlar</h2><div class="scroll ek-row-actions" role="region" aria-label="Katalog kayıtları" tabindex="0"><table><thead><tr><th>Ad</th><th>Durum</th><th>İşlemler</th></tr></thead><tbody><?php foreach($rows as $record):?><tr><td><?=h($record['name'])?></td><td><?=$record['active']?'Aktif':'Arşivde'?></td><td><a href="<?=h($catalogPath)?>?edit=<?=(int)$record['id']?>">Düzenle</a><form method="post" onsubmit="return confirm('Bu kaydın arşiv durumu değiştirilsin mi? Geçmiş teklifler korunur.')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="id" value="<?=(int)$record['id']?>"><input type="hidden" name="action" value="<?=$record['active']?'archive':'restore'?>"><button class="secondary"><?=$record['active']?'Sil / Arşivle':'Geri yükle'?></button></form></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="3">Henüz kayıt yok.</td></tr><?php endif;?></tbody></table></div></section></div><?php bottom(); ?>
