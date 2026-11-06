<?php
require __DIR__.'/inc/core.php'; auth();$canManage=ekAdmin();
$pdo=db(); $error=''; $message='';
try { $ready=(bool)$pdo->query("SHOW TABLES LIKE 'moving_content_templates'")->fetchColumn(); }
catch(Throwable $e){$ready=false;$error='Şablon tablosu kontrol edilemedi.';}
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!$canManage){http_response_code(403);exit("Yönetici yetkisi gerekir.");}
 verify();
 if(!$ready){$error='Önce şablon tablosunu kurun.';}
 else {
  $id=max(0,(int)($_POST['id']??0));$action=(string)($_POST['action']??'');
  if(!$id||!in_array($action,['archive','restore'],true)){$error='Geçersiz işlem.';}
  else {try{
   $st=$pdo->prepare('UPDATE moving_content_templates SET active=? WHERE id=?');
   $st->execute([$action==='restore'?1:0,$id]);
   if(!$st->rowCount()){$error='Şablon bulunamadı veya zaten bu durumda.';}
   else {redirect('templates.php?result='.$action);}
  }catch(Throwable $e){$error='Şablon durumu güncellenemedi.';}}
 }
}
$rows=[];if($ready){try{$rows=$pdo->query('SELECT id,name,transport,language,active,service_scope,updated_at FROM moving_content_templates ORDER BY active DESC,transport,language,name')->fetchAll();}catch(Throwable $e){$error='Şablon listesi yüklenemedi.';}}
require __DIR__.'/inc/layout.php';top('Teklif İçerik Şablonları');
$labels=['road'=>'Karayolu','sea_fcl'=>'Denizyolu FCL','sea_lcl'=>'Denizyolu LCL','other'=>'Diğer'];
?>
<style>
.ct-wrap{max-width:1160px}.ct-header{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:22px}.ct-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:16px}.ct-card{background:#fff;border:1px solid #dbe4ef;border-radius:14px;padding:20px;box-shadow:0 4px 16px #122e4b0a}.ct-muted{color:#475569;font-size:13px;line-height:1.5}.ct-btn{display:inline-block;border:1px solid #d4deeb;border-radius:8px;padding:9px 13px;color:#173b68;text-decoration:none;background:white;font-weight:600;font-size:13px;cursor:pointer}.ct-primary{background:#16365f;color:#fff;border-color:#16365f}.ct-actions{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-top:16px}.ct-actions form{margin:0}.ct-warning{color:#a43727;border-color:#edc7c0}.ct-tag{display:inline-block;border-radius:30px;padding:5px 9px;background:#e9f0f8;color:#244567;font-size:12px;margin:8px 4px 8px 0}.ct-inactive{opacity:.75}.ct-alert{background:#fff7ed;border:1px solid #fed7aa;padding:14px;border-radius:10px;margin:12px 0}
</style>
<div class="ct-wrap"><div class="ct-header"><div><h1>Teklif İçerik Şablonları</h1><p class="ct-muted">Karayolu ve denizyolu için hazır teklif metinleri. Şablonu arşivlemek geçmiş teklifleri silmez.</p></div><div><a class="ct-btn" href="settings-hub.php">← Ayarlara Dön</a> <?php if($canManage):?><a class="ct-btn" href="settings.php">Teklif Belgesi Alt Bilgisi</a><?php endif;?> <?php if($ready&&$canManage):?><a class="ct-btn ct-primary" href="v119-template-edit.php">+ Yeni Şablon</a><?php endif;?></div></div>
<?php if($error):?><div class="ct-alert"><?=h($error)?></div><?php endif;?>
<?php if(isset($_GET['result'])&&in_array($_GET['result'],['archive','restore'],true)):?><p class="ct-alert"><?= $_GET['result']==='archive'?'Şablon arşivlendi. Eski teklifler korunur.':'Şablon yeniden etkinleştirildi.' ?></p><?php endif;?>
<?php if(!$ready):?><div class="ct-card">İçerik şablonları henüz kurulmamış. <a class="ct-btn ct-primary" href="system-check.php">Kurulum Sayfası</a></div><?php else:?>
<div class="ct-grid"><?php foreach($rows as $r):?><article class="ct-card <?=!$r['active']?'ct-inactive':''?>"><strong><?=h($r['name'])?></strong><div><span class="ct-tag"><?=h($labels[$r['transport']]??$r['transport'])?></span><span class="ct-tag"><?=h($r['language'])?></span><span class="ct-tag"><?=$r['active']?'Aktif':'Arşivde'?></span></div><p class="ct-muted"><?=h(mb_strimwidth(trim(strip_tags($r['service_scope']?:'Henüz hizmet kapsamı girilmedi.')),0,170,'…','UTF-8'))?></p><?php if($canManage):?><div class="ct-actions"><a class="ct-btn ct-primary" href="v119-template-edit.php?id=<?=(int)$r['id']?>">Düzenle</a><a class="ct-btn" href="v119-template-edit.php?copy=<?=(int)$r['id']?>">Kopyala</a><form method="post" onsubmit="return confirm(<?=h(json_encode($r['active']?'Bu şablon arşivlensin mi? Yeni tekliflerde görünmeyecek, geçmiş teklifler etkilenmeyecek.':'Bu şablon yeniden aktif edilsin mi?',JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT))?>)"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><input type="hidden" name="action" value="<?=$r['active']?'archive':'restore'?>"><button type="submit" class="ct-btn <?=$r['active']?'ct-warning':''?>"><?=$r['active']?'Sil / Arşivle':'Geri Yükle'?></button></form></div><?php endif;?></article><?php endforeach;?></div><?php if(!$rows):?><p class="ct-alert">Henüz kayıtlı şablon yok. Yeni şablon oluşturun.</p><?php endif;endif;?></div><?php bottom(); ?>
