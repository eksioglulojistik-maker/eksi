<?php
require __DIR__.'/inc/core.php';auth();
require_once __DIR__.'/inc/survey-workflow.php';
require_once __DIR__.'/inc/survey-operations.php';
require_once __DIR__.'/inc/survey-catalog.php';
require_once __DIR__.'/inc/survey-catalog-view.php';
$isCatalogJson=($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='SurveyCatalog';
$workspaceReady=svOpsReady(db());
function surveyAdmin(){return ekAdmin();}
function surveyCan($s){return surveyAdmin() || (int)$s['assigned_user_id']===(int)$_SESSION['uid'];}
function surveyGet($id){$q=db()->prepare('SELECT s.*,c.name AS customer_name FROM ek_surveys s JOIN customers c ON c.id=s.customer_id WHERE s.id=?');$q->execute([$id]);return $q->fetch();}
$err='';$notice=(string)($_SESSION['sv_notice']??'');unset($_SESSION['sv_notice']);$id=max(0,(int)($_GET['id']??$_POST['id']??0));$survey=$id?surveyGet($id):null;
if($id && (!$survey || !surveyCan($survey))){http_response_code(403);exit('Ekspertize erişim yetkiniz yok.');}
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify();$action=(string)($_POST['action']??'');
 $uploadedPhoto=null;$deletedPhoto=null;
 try{
  if(!in_array($action,['create','brief','item','update_item','remove','photo','delete_photo','status','delete_survey','revise','lifecycle','catalog_add','catalog_step','catalog_clear'],true))throw new RuntimeException('Geçersiz ekspertiz işlemi.');
  if($action==='create'){
   if(!surveyAdmin())throw new RuntimeException('Yalnızca yönetici ekspertiz açabilir.');
   $customer=(int)($_POST['customer_id']??0);$u=(int)($_POST['assigned_user_id']??0);$type=(string)($_POST['move_type']??'domestic');
   if(!in_array($type,['domestic','international'],true))throw new RuntimeException('Taşıma türü geçersiz.');
   $check=db()->prepare('SELECT COUNT(*) FROM customers WHERE id=?');$check->execute([$customer]);if(!$check->fetchColumn())throw new RuntimeException('Müşteri bulunamadı.');
   if($u){$check=db()->prepare('SELECT COUNT(*) FROM users WHERE id=?');$check->execute([$u]);if(!$check->fetchColumn())throw new RuntimeException('Personel bulunamadı.');}
   $date=ekSurveyAppointment((string)($_POST['appointment_at']??''));
   $q=db()->prepare('INSERT INTO ek_surveys(customer_id,assigned_user_id,move_type,origin_address,destination_address,appointment_at,created_by,status) VALUES(?,?,?,?,?,?,?,?)');
   $q->execute([$customer,$u?:null,$type,mb_substr(trim((string)($_POST['origin_address']??'')),0,2000),mb_substr(trim((string)($_POST['destination_address']??'')),0,2000),$date,(int)$_SESSION['uid'],$u?'assigned':'draft']);
   redirect('ekspertiz.php?id='.db()->lastInsertId());
  }
  if(!$survey)throw new RuntimeException('Ekspertiz bulunamadı.');
  if($action!=='delete_survey'){
   db()->beginTransaction();$lock=db()->prepare('SELECT * FROM ek_surveys WHERE id=? FOR UPDATE');$lock->execute([$id]);$freshSurvey=$lock->fetch(PDO::FETCH_ASSOC);if($freshSurvey)$survey=array_merge($survey,$freshSurvey);else $survey=null;
   if(!$survey||!surveyCan($survey))throw new RuntimeException('Ekspertize erişim yetkiniz yok.');
  }
  if($action==='revise'){
   if(!surveyAdmin()||!$workspaceReady)throw new RuntimeException('Revizyon için yönetici ve saha altyapısı gerekir.');
   $new=svOpsRevise(db(),$survey,(int)$_SESSION['uid'],trim((string)($_POST['reason']??'')));db()->commit();redirect('ekspertiz.php?id='.$new);
  }
  if($action==='lifecycle'){
   if(!surveyAdmin()||!$workspaceReady)throw new RuntimeException('Durum değişikliği için yönetici ve saha altyapısı gerekir.');
   $next=(string)($_POST['next_status']??'');$allowed=['draft'=>['cancelled'],'assigned'=>['cancelled'],'cancelled'=>['draft'],'approved'=>['completed']];
   if(!in_array($next,$allowed[$survey['status']]??[],true))throw new RuntimeException('Bu durum geçişine izin verilmiyor.');
   if($next==='completed'&&!svOpsQuotes(db(),$id))throw new RuntimeException('Tamamlamak için ekspertize bağlı kayıtlı teklif gerekir.');
   $u=db()->prepare('UPDATE ek_surveys SET status=? WHERE id=?');$u->execute([$next,$id]);svOpsEvent(db(),$id,(int)$_SESSION['uid'],'status_changed',$survey['status'].' → '.$next);db()->commit();$_SESSION['sv_notice']='Ekspertiz durumu güncellendi.';redirect('ekspertiz.php?id='.$id.'#sv-review');
  }
  if($action==='delete_survey'){
   if(!surveyAdmin())throw new RuntimeException('Yalnızca yönetici ekspertiz silebilir.');
   if((string)($_POST['confirm_delete']??'')!=='DELETE')throw new RuntimeException('Silme onayı geçersiz.');
   $pdo=db();$pdo->beginTransaction();
   try{
    $lock=$pdo->prepare('SELECT id FROM ek_surveys WHERE id=? FOR UPDATE');$lock->execute([$id]);
    if(!$lock->fetchColumn())throw new RuntimeException('Ekspertiz bulunamadı.');
    if(svOpsQuotes($pdo,$id))throw new RuntimeException('Bağlı teklif bulunan ekspertiz korunur.');
    if($workspaceReady){$dep=$pdo->prepare('SELECT COUNT(*) FROM ek_survey_workspace WHERE parent_id=? OR (survey_id=? AND parent_id IS NOT NULL)');$dep->execute([$id,$id]);if($dep->fetchColumn())throw new RuntimeException('Revizyon geçmişi olan ekspertiz silinemez.');}
    $p=$pdo->prepare('SELECT file_path FROM ek_survey_photos WHERE survey_id=?');$p->execute([$id]);$paths=$p->fetchAll(PDO::FETCH_COLUMN);
    // Envanter ve fotoğraf satırları FK ON DELETE CASCADE ile silinir.
    $d=$pdo->prepare('DELETE FROM ek_surveys WHERE id=?');$d->execute([$id]);
    if($workspaceReady){foreach(['ek_survey_workspace','ek_survey_activity'] as $table){$del=$pdo->prepare('DELETE FROM '.$table.' WHERE survey_id=?');$del->execute([$id]);}}
    $pdo->commit();
   }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
   $dir=__DIR__.'/survey_uploads/';
   foreach($paths as $path){
    if(is_string($path)&&preg_match('/^[a-f0-9]{40}\.(?:jpg|png|webp)$/D',$path)){
     $file=$dir.$path;if(is_file($file)&&!is_link($file))@unlink($file);
    }
   }
   redirect('ekspertiz.php?deleted=1');
  }
  if(in_array($survey['status'],['approved','completed','cancelled'],true))throw new RuntimeException('Onaylanmış ekspertiz değiştirilemez.');
  if(in_array($action,['catalog_add','catalog_step','catalog_clear'],true)){
   [$requestId,$requestHash]=svCatalogReceipt($_POST);$receiptKey=(int)$_SESSION['uid'].':'.$id.':'.$requestId;
   if(isset($_SESSION['sv_catalog_receipts'][$receiptKey])){
    if(!hash_equals($_SESSION['sv_catalog_receipts'][$receiptKey],$requestHash))throw new RuntimeException('İşlem kimliği farklı bir istek için kullanılmış.');
    $snapshot=svCatalogSnapshot(db(),$id);db()->commit();
    if($isCatalogJson){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['ok'=>true,'snapshot'=>$snapshot],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
    redirect('ekspertiz.php?id='.$id.'&room='.rawurlencode((string)($_POST['room_key']??'living')).'#sv-inventory');
   }
   if($action==='catalog_add'){
    if(!is_string($_POST['catalog_key']??null)||!is_string($_POST['room_key']??null))throw new RuntimeException('Katalog seçimi geçersiz.');
    svCatalogAdd(db(),$id,$_POST['catalog_key'],$_POST['room_key']);
   }elseif($action==='catalog_clear'){
    svCatalogClear(db(),$id,is_string($_POST['revision']??null)?$_POST['revision']:'',($_POST['confirm_clear']??'')==='1');
   }else{svCatalogStep(db(),$id,(int)filter_var($_POST['item_id']??0,FILTER_VALIDATE_INT),(int)filter_var($_POST['delta']??0,FILTER_VALIDATE_INT),($_POST['confirm_remove']??'')==='1');}
   $snapshot=svCatalogSnapshot(db(),$id);if($workspaceReady)svOpsEvent(db(),$id,(int)$_SESSION['uid'],$action);
   db()->commit();$_SESSION['sv_catalog_receipts'][$receiptKey]=$requestHash;$_SESSION['sv_catalog_receipts']=array_slice($_SESSION['sv_catalog_receipts'],-100,null,true);
   if($isCatalogJson){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['ok'=>true,'snapshot'=>$snapshot],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
   redirect('ekspertiz.php?id='.$id.'&room='.rawurlencode((string)($_POST['room_key']??'living')).'#sv-inventory');
  }
  if($action==='brief'){
   $date=ekSurveyAppointment((string)($_POST['appointment_at']??''));
   $origin=mb_substr(trim((string)($_POST['origin_address']??'')),0,2000);
   $destination=mb_substr(trim((string)($_POST['destination_address']??'')),0,2000);
   // Recheck the lock in the write itself; an already approved survey stays immutable.
   $q=db()->prepare("UPDATE ek_surveys SET origin_address=?,destination_address=?,appointment_at=? WHERE id=? AND status<>'approved'");
   $q->execute([$origin,$destination,$date,$id]);
   if(!$workspaceReady)throw new RuntimeException('Önce saha altyapısını hazırlayın.');
   svOpsSave(db(),$id,(int)$_SESSION['uid'],svOpsFields($_POST));
   if(surveyAdmin()){$assigned=max(0,(int)($_POST['assigned_user_id']??$survey['assigned_user_id']??0));if($assigned){$u=db()->prepare('SELECT COUNT(*) FROM users WHERE id=?');$u->execute([$assigned]);if(!$u->fetchColumn())throw new RuntimeException('Personel bulunamadı.');}$u=db()->prepare('UPDATE ek_surveys SET assigned_user_id=?,status=? WHERE id=?');$u->execute([$assigned?:null,$assigned?'assigned':'draft',$id]);}
   $notice='Saha bilgileri kaydedildi.';
  }elseif($action==='item'){
   $room=mb_substr(trim((string)($_POST['room']??'')),0,100);$name=mb_substr(trim((string)($_POST['item_name']??'')),0,190);
   $qty=(int)($_POST['quantity']??0);$vol=svOpsVolume($_POST)??(float)($_POST['unit_volume']??-1);
   if(!$room||!$name||$qty<1||$qty>500||!is_finite($vol)||$vol<0||$vol>100)throw new RuntimeException('Eşya bilgilerini kontrol edin.');
   $q=db()->prepare('INSERT INTO ek_survey_items(survey_id,room,item_name,quantity,unit_volume,notes) VALUES(?,?,?,?,?,?)');$q->execute([$id,$room,$name,$qty,$vol,svOpsMeasurementNote($_POST)]);$notice='Eşya eklendi.';
  }elseif($action==='update_item'){
   $itemId=(int)($_POST['item_id']??0);
   $room=mb_substr(trim((string)($_POST['room']??'')),0,100);
   $name=mb_substr(trim((string)($_POST['item_name']??'')),0,190);
   $qty=(int)($_POST['quantity']??0);$vol=svOpsVolume($_POST)??(float)($_POST['unit_volume']??-1);
   if(!$room||!$name||$qty<1||$qty>500||!is_finite($vol)||$vol<0||$vol>100)throw new RuntimeException('Eşya bilgilerini kontrol edin.');
   $q=db()->prepare('UPDATE ek_survey_items SET room=?,item_name=?,quantity=?,unit_volume=? WHERE id=? AND survey_id=?');
   $q->execute([$room,$name,$qty,$vol,$itemId,$id]);$notice=$q->rowCount()?'Eşya güncellendi.':'Eşya kaydı bulunamadı.';
  }elseif($action==='remove'){
   $q=db()->prepare('DELETE FROM ek_survey_items WHERE id=? AND survey_id=?');$q->execute([(int)($_POST['item_id']??0),$id]);$notice='Eşya kaldırıldı.';
  }elseif($action==='photo'){
   if(empty($_FILES['photo'])||$_FILES['photo']['error']!==UPLOAD_ERR_OK)throw new RuntimeException('Fotoğraf yüklenemedi.');
   $file=$_FILES['photo'];if($file['size']>8*1024*1024)throw new RuntimeException('Fotoğraf en fazla 8 MB olabilir.');
   $info=@getimagesize($file['tmp_name']);$mime=$info['mime']??'';$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
   if(!$ext)throw new RuntimeException('Yalnızca JPG, PNG veya WebP fotoğraf yükleyin.');
   $dir=__DIR__.'/survey_uploads';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Yükleme klasörü açılamadı.');
   $name=bin2hex(random_bytes(20)).'.'.$ext;$dest=$dir.'/'.$name;
   if(!move_uploaded_file($file['tmp_name'],$dest))throw new RuntimeException('Fotoğraf kaydedilemedi.');
   $uploadedPhoto=$dest;
   chmod($dest,0600);
   $q=db()->prepare('INSERT INTO ek_survey_photos(survey_id,room,file_path,mime) VALUES(?,?,?,?)');$q->execute([$id,mb_substr(trim((string)($_POST['room']??'Genel')),0,100),$name,$mime]);$notice='Fotoğraf güvenli alana kaydedildi.';
   }elseif($action==='delete_photo'){
   $photoId=max(0,(int)($_POST['photo_id']??0));
   $q=db()->prepare('SELECT file_path FROM ek_survey_photos WHERE id=? AND survey_id=?');$q->execute([$photoId,$id]);$filename=$q->fetchColumn();
   if(!$filename)throw new RuntimeException('Fotoğraf bulunamadı.');
   $q=db()->prepare('DELETE FROM ek_survey_photos WHERE id=? AND survey_id=?');$q->execute([$photoId,$id]);
   if(preg_match('/^[a-f0-9]{40}\.(jpg|png|webp)$/D',$filename)) $deletedPhoto=__DIR__.'/survey_uploads/'.$filename;
   $notice='Fotoğraf silindi.';
  }elseif($action==='status'){
   if(!surveyAdmin())throw new RuntimeException('Yalnızca yönetici onaylayabilir.');
   if(!$workspaceReady)throw new RuntimeException('Önce saha altyapısını hazırlayın.');
   $missing=svOpsMissing(svOpsMeta(db(),$id)['fields']);if($missing)throw new RuntimeException(implode(' ',$missing));
   if(trim((string)$survey['origin_address'])===''||trim((string)$survey['destination_address'])==='')throw new RuntimeException('Onaydan önce çıkış ve varış adreslerini tamamlayın.');
   $q=db()->prepare('SELECT COALESCE(SUM(quantity*unit_volume),0) FROM ek_survey_items WHERE survey_id=?');$q->execute([$id]);$volume=(float)$q->fetchColumn();
   if($volume<=0)throw new RuntimeException('Önce eşya envanteri oluşturun.');
   $q=db()->prepare("UPDATE ek_surveys SET status='approved',approved_volume=? WHERE id=?");$q->execute([$volume,$id]);$notice='Ekspertiz onaylandı. Teklif Hazırla bağlantısıyla verileri teklif formuna aktarabilirsiniz.';
  }
  if($workspaceReady)svOpsEvent(db(),$id,(int)$_SESSION['uid'],$action);
  if(db()->inTransaction())db()->commit();
  $uploadedPhoto=null;
  if($deletedPhoto!==null&&is_file($deletedPhoto)&&!@unlink($deletedPhoto))error_log('Survey photo cleanup pending: '.basename($deletedPhoto));
  $_SESSION['sv_notice']=$notice;$tab=in_array($action,['item','update_item','remove'],true)?'sv-inventory':(in_array($action,['photo','delete_photo'],true)?'sv-photos':($action==='status'?'sv-review':'sv-brief'));redirect('ekspertiz.php?id='.$id.'#'.$tab);
  $survey=surveyGet($id);
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();if($uploadedPhoto!==null&&is_file($uploadedPhoto)&&!@unlink($uploadedPhoto))error_log('Survey upload cleanup pending: '.basename($uploadedPhoto));$err=$e instanceof PDOException?'İşlem kaydedilemedi. Bilgiler korunuyor; tekrar deneyin.':$e->getMessage();if($isCatalogJson){http_response_code(422);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['ok'=>false,'error'=>$err],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}}
}
require __DIR__.'/inc/layout.php';top('Ekspertiz Yönetimi');
$surveyStatusLabels=['draft'=>'Taslak','assigned'=>'Personel atandı','approved'=>'Onaylandı','cancelled'=>'İptal edildi','completed'=>'Tamamlandı'];
?><link rel="stylesheet" href="assets/ekspertiz.css?v=3.6.32"><div class="sv-workspace sv-compact">
<header class="sv-head"><div><small>EKŞİOĞLU LOJİSTİK · SAHA OPERASYONU</small><h1><?= $survey?'Ekspertiz #'.(int)$id:'Ekspertizler' ?></h1><p><?= $survey?h($survey['customer_name']).' · '.h($surveyStatusLabels[$survey['status']]??$survey['status']):'Randevudan teklif hazırlığına, ev taşıma ekspertizlerinizi tek yerden yönetin.' ?></p></div><div class="sv-actions">
<?php if($survey):?><a class="sv-btn secondary" href="ekspertiz.php">Listeye dön</a><a class="sv-btn secondary" href="ekspertiz-rapor.php?id=<?=$id?>" target="_blank" rel="noopener">Rapor / Yazdır</a><?php if($survey['status']==='approved'):?><a class="sv-btn" href="quote.php?survey_id=<?=$id?>">Teklif Hazırla →</a><?php endif;?>
<?php elseif(surveyAdmin()):?><a class="sv-btn" href="#sv-create">＋ Yeni ekspertiz</a><a class="sv-btn secondary" href="ekspertiz-ai-settings.php">Yapay zekâ ayarları</a><?php endif;?></div></header>
<?php if(!$workspaceReady && surveyAdmin()):?><div class="sv-alert">Yeni saha bilgileri ve revizyonlar için <a href="ekspertiz-workspace-setup.php">saha altyapısını hazırlayın</a>.</div><?php endif;?>
<?php if(($_GET['deleted']??'')==='1'):?><div class="sv-alert">Ekspertiz kaydı kalıcı olarak silindi.</div><?php endif;?>
<?php if($err):?><div class="sv-alert bad"><?=h($err)?></div><?php endif;?><?php if($notice):?><div class="sv-alert"><?=h($notice)?></div><?php endif;?>
<?php if(!$survey):
 $filters=ekSurveyFilters($_GET);$where=[];$params=[];
 if(!surveyAdmin()){$where[]='s.assigned_user_id=?';$params[]=(int)$_SESSION['uid'];}
 if($filters['q']!==''){$where[]='(c.name LIKE ? OR s.origin_address LIKE ? OR s.destination_address LIKE ?)';$term='%'.$filters['q'].'%';array_push($params,$term,$term,$term);}
 if($filters['status']!==''){$where[]='s.status=?';$params[]=$filters['status'];}
 if($filters['type']!==''){$where[]='s.move_type=?';$params[]=$filters['type'];}
 $clause=$where?' WHERE '.implode(' AND ',$where):'';
 $q=db()->prepare('SELECT COUNT(*) FROM ek_surveys s JOIN customers c ON c.id=s.customer_id'.$clause);$q->execute($params);$count=(int)$q->fetchColumn();
 $pages=max(1,(int)ceil($count/20));$page=min($filters['page'],$pages);$offset=($page-1)*20;
 $q=db()->prepare('SELECT s.*,c.name customer_name,u.name assigned_name,(SELECT COALESCE(SUM(i.quantity*i.unit_volume),0) FROM ek_survey_items i WHERE i.survey_id=s.id) total_volume FROM ek_surveys s JOIN customers c ON c.id=s.customer_id LEFT JOIN users u ON u.id=s.assigned_user_id'.$clause.' ORDER BY s.id DESC LIMIT 20 OFFSET '.$offset);$q->execute($params);$rows=$q->fetchAll();$pageLinks=svOpsQuoteMap(db(),array_column($rows,'id'));
?>
<section class="sv-card"><div class="sv-section-title"><div><small>EKSPERTİZ TAKİBİ</small><h2><?=surveyAdmin()?'Ekspertiz çalışma listesi':'Bana atanan ekspertizler'?></h2><p>Planlanan işleri bulun, saha dosyasını açın ve teklif hazırlığına geçin.</p></div><span class="sv-pill"><?=$count?> kayıt</span></div>
<form method="get" class="sv-list-filters"><label>Müşteri / güzergâh ara<input type="search" name="q" value="<?=h($filters['q'])?>" maxlength="100" placeholder="Müşteri veya adres"></label><label>Durum<select name="status"><option value="">Tüm durumlar</option><?php foreach($surveyStatusLabels as $key=>$label):?><option value="<?=h($key)?>" <?=$filters['status']===$key?'selected':''?>><?=h($label)?></option><?php endforeach;?></select></label><label>Taşıma<select name="type"><option value="">Tüm taşımalar</option><option value="international" <?=$filters['type']==='international'?'selected':''?>>Uluslararası</option><option value="domestic" <?=$filters['type']==='domestic'?'selected':''?>>Yurtiçi</option></select></label><button class="sv-btn">Filtrele</button><a class="sv-reset" href="ekspertiz.php">Temizle</a></form>
<div class="sv-table sv-responsive-table"><table aria-label="Ekspertiz çalışma listesi"><thead><tr><th>Dosya / Müşteri</th><th>Saha planı</th><th>Güzergâh</th><th>Durum / Hacim</th><th>İşlem</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td data-label="Dosya / Müşteri"><a class="sv-record-title" href="ekspertiz.php?id=<?=(int)$r['id']?>"><?=h($r['customer_name'])?></a><small class="sv-muted">#<?=(int)$r['id']?> · <?=$r['move_type']==='international'?'Uluslararası':'Yurtiçi'?></small></td><td data-label="Saha planı"><?=h($r['appointment_at']?date('d.m.Y H:i',strtotime($r['appointment_at'])):'Randevu bekliyor')?><small class="sv-muted"><?=h($r['assigned_name']?:'Personel atanmadı')?></small></td><td data-label="Güzergâh"><div class="sv-route-line"><?=h($r['origin_address']?:'Çıkış adresi eksik')?></div><div class="sv-route-line sv-muted">→ <?=h($r['destination_address']?:'Varış adresi eksik')?></div></td><td data-label="Durum / Hacim"><span class="sv-pill"><?=h($surveyStatusLabels[$r['status']]??$r['status'])?></span><small class="sv-muted"><?=number_format((float)$r['total_volume'],2,',','.')?> m³</small></td><td data-label="İşlem"><a class="sv-btn secondary" href="ekspertiz.php?id=<?=(int)$r['id']?>">Dosyayı aç →</a><?php if(!empty($pageLinks[(int)$r['id']])):?><a class="sv-muted" href="ekspertiz.php?id=<?=(int)$r['id']?>#sv-review"><?=count($pageLinks[(int)$r['id']])?> bağlı teklif →</a><?php endif;?></td></tr><?php endforeach;?></tbody></table></div>
<?php if(!$rows):?><div class="sv-empty"><h3>Ekspertiz bulunamadı</h3><p><?= $count===0&&($filters['q']!==''||$filters['status']!==''||$filters['type']!=='')?'Filtreleri değiştirerek tekrar arayın.':'İlk ekspertizi planlayarak saha çalışmalarına başlayın.' ?></p></div><?php endif;?>
<nav class="sv-pagination" aria-label="Ekspertiz sayfaları"><span>Sayfa <?=$page?> / <?=$pages?></span><?php if($page>1):?><a class="sv-btn secondary" href="?<?=h(http_build_query(array_merge($filters,['page'=>$page-1])))?>">Önceki</a><?php endif;?><?php if($page<$pages):?><a class="sv-btn secondary" href="?<?=h(http_build_query(array_merge($filters,['page'=>$page+1])))?>">Sonraki</a><?php endif;?></nav></section>
<?php if(surveyAdmin()):$customers=db()->query('SELECT id,name FROM customers ORDER BY name')->fetchAll();$users=db()->query('SELECT id,name FROM users ORDER BY name')->fetchAll();?><details class="sv-card sv-create" id="sv-create"<?=isset($_GET['new'])?' open':''?>><summary>Yeni ekspertiz planla</summary><p>Müşteriyi seçin, saha sorumlusunu ve randevuyu belirleyin. Adresleri saha çalışması sırasında tamamlayabilirsiniz.</p><form method="post" class="sv-grid"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="create"><label>Müşteri<select required name="customer_id"><option value="">Seçiniz</option><?php foreach($customers as $c):?><option value="<?=$c['id']?>"><?=h($c['name'])?></option><?php endforeach;?></select></label><label>Taşıma türü<select name="move_type"><option value="domestic">Yurtiçi evden eve</option><option value="international">Uluslararası evden eve</option></select></label><label>Personel<select name="assigned_user_id"><option value="0">Henüz atanmadı</option><?php foreach($users as $u):?><option value="<?=$u['id']?>"><?=h($u['name'])?></option><?php endforeach;?></select></label><label>Randevu<input type="datetime-local" name="appointment_at"></label><label>Çıkış adresi<textarea name="origin_address" rows="2"></textarea></label><label>Varış adresi<textarea name="destination_address" rows="2"></textarea></label><button class="sv-btn">Ekspertiz Oluştur</button></form></details><?php endif;?><?php else:$q=db()->prepare('SELECT * FROM ek_survey_items WHERE survey_id=? ORDER BY room,id');$q->execute([$id]);$items=$q->fetchAll();$total=0;$pieces=0;foreach($items as $i){$total+=$i['quantity']*$i['unit_volume'];$pieces+=$i['quantity'];}$q=db()->prepare('SELECT id,room,created_at FROM ek_survey_photos WHERE survey_id=? ORDER BY id DESC');$q->execute([$id]);$photos=$q->fetchAll();$locked=in_array($survey['status'],['approved','completed','cancelled'],true);$meta=$workspaceReady?svOpsMeta(db(),$id):['parent_id'=>null,'fields'=>[]];$site=$meta['fields'];$linkedQuotes=svOpsQuotes(db(),$id);$staffUsers=surveyAdmin()?db()->query('SELECT id,name FROM users ORDER BY name')->fetchAll():[];?>
<?php $readiness=array_merge(ekSurveyReadiness($survey,$items),svOpsMissing($site));?>
<div class="sv-summarybar"><span><small>Randevu</small><b><?=h($survey['appointment_at']?date('d.m.Y · H:i',strtotime($survey['appointment_at'])):'Planlanmadı')?></b></span><span><small>Tahmini hacim</small><b><?=number_format($total,2,',','.')?> m³</b></span><span><small>Eşya</small><b><?=$pieces?> adet</b></span><span><small>Sorumlu</small><b><?=h(svOpsAssignee(db(),(int)$survey['assigned_user_id']))?></b></span></div>
<?php if($meta['parent_id']):?><div class="sv-alert">Revizyon dosyası · <a href="ekspertiz.php?id=<?=(int)$meta['parent_id']?>">Önceki onaylı ekspertiz #<?=(int)$meta['parent_id']?></a>. Önceki fotoğraflar kaynak dosyada korunur.</div><?php endif;?>
<nav class="sv-workflow" aria-label="Ekspertiz çalışma adımları"><a data-sv-tab="sv-brief-panel" href="#sv-brief"><b>01</b> Saha bilgileri</a><a data-sv-tab="sv-inventory-panel" href="#sv-inventory"><b>02</b> Envanter & hacim</a><a data-sv-tab="sv-photos-panel" href="#sv-photos"><b>03</b> Fotoğraflar</a><a data-sv-tab="sv-review-panel" href="#sv-review"><b>04</b> Kontrol & teklif</a></nav>
<div id="sv-brief-panel" class="sv-tabpanel"><section class="sv-card" id="sv-brief"><div class="sv-section-title"><div><small>SAHA DOSYASI</small><h2>Randevu ve güzergâh</h2></div><span class="sv-pill"><?=h($surveyStatusLabels[$survey['status']]??$survey['status'])?></span></div>
<?php if(!$locked):?><form method="post" class="sv-grid"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="brief"><label>Randevu<input type="datetime-local" name="appointment_at" value="<?=h($survey['appointment_at']?date('Y-m-d\TH:i',strtotime($survey['appointment_at'])):'')?>"></label><div class="sv-help">Saha adreslerini kontrol edin. Bu bilgiler onaydan sonra teklif hazırlığına aktarılır.</div><label>Çıkış adresi<textarea name="origin_address" rows="3" maxlength="2000"><?=h($survey['origin_address'])?></textarea></label><label>Varış adresi<textarea name="destination_address" rows="3" maxlength="2000"><?=h($survey['destination_address'])?></textarea></label><?php if(surveyAdmin()):?><label>Sorumlu personel<select name="assigned_user_id"><option value="0">Atanmadı</option><?php foreach($staffUsers as $u):?><option value="<?=(int)$u['id']?>" <?=(int)$u['id']===(int)$survey['assigned_user_id']?'selected':''?>><?=h($u['name'])?></option><?php endforeach;?></select></label><?php endif;?><?php svOpsRenderFields($site,false);?><button class="sv-btn">Saha bilgilerini kaydet</button></form>
<?php else:?><div class="sv-grid"><div><small>Randevu</small><p><?=h($survey['appointment_at']?date('d.m.Y · H:i',strtotime($survey['appointment_at'])):'Belirtilmedi')?></p></div><div class="sv-help">Onaylanmış saha bilgileri korunur.</div><div><small>Çıkış adresi</small><p><?=nl2br(h($survey['origin_address']?:'Belirtilmedi'))?></p></div><div><small>Varış adresi</small><p><?=nl2br(h($survey['destination_address']?:'Belirtilmedi'))?></p></div></div><?php svOpsRenderFields($site,true);?><?php endif;?></section>
</div><div id="sv-inventory-panel" class="sv-tabpanel"><?php $selectedRoom=is_string($_GET['room']??null)&&isset(svCatalogRooms()[$_GET['room']])?$_GET['room']:'living';svCatalogView($id,$items,$locked,$selectedRoom);?>
<?php if(!$locked):?><details class="sv-card svc-manual" id="svc-manual"><summary>＋ Özel eşya / ölçüyle ekle</summary><p>Katalogda bulunmayan eşyayı seçili odaya ekleyin. İsterseniz üç ölçü girerek birim hacmi hesaplayın.</p><form method="post" class="sv-grid"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="item"><label>Oda<select name="room"><?php foreach(svCatalogRooms() as $roomKey=>$room):?><option value="<?=h($room[0])?>"><?=h($room[0])?></option><?php endforeach;?></select></label><label>Eşya<input name="item_name" id="sv-item-name" list="sv-catalog" required maxlength="190" placeholder="Eşya seçin veya yazın"><datalist id="sv-catalog"><?php foreach(svCatalogItems() as $entry):?><option value="<?=h($entry[0])?>"><?php endforeach;?></datalist></label><label>Adet<input type="number" name="quantity" value="1" min="1" max="500" required></label><label>Birim hacim (m³)<input data-ek-volume type="number" id="sv-unit-volume" name="unit_volume" value="0.5" min="0" max="100" step="0.001" required></label><fieldset class="sv-measures"><legend>İsteğe bağlı ölçüler · cm</legend><label>En<input type="number" name="width_cm" min="0.1" max="1000" step="0.1" inputmode="decimal"></label><label>Boy<input type="number" name="length_cm" min="0.1" max="1000" step="0.1" inputmode="decimal"></label><label>Yükseklik<input type="number" name="height_cm" min="0.1" max="1000" step="0.1" inputmode="decimal"></label><small>Üç ölçü girilirse birim hacim sunucuda hesaplanır. Ölçüleri boş bırakırsanız manuel hacim kullanılır.</small><output id="sv-measured-volume" aria-live="polite"></output></fieldset><button class="sv-btn">Eşya Ekle</button></form></details><?php endif;?>
</div><div id="sv-photos-panel" class="sv-tabpanel"><section class="sv-card" id="sv-photos"><div class="sv-section-title"><div><small>SAHA FOTOĞRAFLARI</small><h2>Fotoğraf arşivi</h2><p>Telefon kamerasıyla çekin veya galeriden yükleyin. Fotoğraflar yetkili kullanıcı erişimiyle görüntülenir.</p></div><span class="sv-pill"><?=count($photos)?> fotoğraf</span></div><?php if(!$locked):?><form method="post" enctype="multipart/form-data" class="sv-grid"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="photo"><label>Oda<input name="room" value="Salon" maxlength="100"></label><label>Fotoğraf çek / seç<input type="file" id="sv-photo-input" name="photo" accept="image/jpeg,image/png,image/webp" capture="environment" required><img id="sv-photo-preview" class="sv-photo-preview" alt="Seçilen fotoğraf önizlemesi" hidden></label><button class="sv-btn">Fotoğrafı Kaydet</button></form><?php endif;?><div class="sv-photos"><?php foreach($photos as $photo):?><div class="sv-photo-entry"><a href="ekspertiz-foto.php?id=<?=$photo['id']?>" target="_blank" rel="noopener"><span>📷</span><b><?=h($photo['room'])?></b><small><?=h($photo['created_at'])?></small></a><?php if(!$locked):?><form method="post" onsubmit="return confirm('Bu fotoğraf kalıcı silinsin mi?')" style="margin-top:8px"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="photo_id" value="<?=(int)$photo['id']?>"><button class="sv-btn secondary" name="action" value="delete_photo" type="submit">Fotoğrafı Sil</button></form><button type="button" class="sv-btn secondary sv-ai-trigger" data-photo-id="<?=(int)$photo['id']?>" data-room="<?=h($photo['room'])?>">Yapay zekâ ile analiz et</button><?php endif;?></div><?php endforeach;?></div><?php if(!$locked && $photos):?><div class="sv-ai-panel" id="sv-ai-panel" hidden aria-live="polite"><h3>Fotoğraftan eşya önerileri</h3><p class="sv-ai-disclaimer">Fotoğraf, analiz için harici yapay zekâ hizmetine gönderilir. Eşya ve hacim değerleri yalnızca tahmindir; personel kontrol etmeden envantere eklenmez.</p><div id="sv-ai-result"></div></div><?php endif;?></section>
</div><div id="sv-review-panel" class="sv-tabpanel"><section class="sv-card" id="sv-review"><div class="sv-section-title"><div><small>SON KONTROL</small><h2>Teklife hazırlık</h2><p>Onay, envanteri kilitler. Adres ve hacim eksiklerini teklif oluşturmadan önce tamamlayın.</p></div><span class="sv-pill"><?=count($readiness)?> eksik</span></div><?php if($readiness):?><ul class="sv-checklist"><?php foreach($readiness as $issue):?><li><?=h($issue)?></li><?php endforeach;?></ul><?php else:?><p class="sv-ready">Güzergâh ve pozitif hacimli envanter hazır.</p><?php endif;?><?php if($survey['status']==='approved'):?><a class="sv-btn" href="quote.php?survey_id=<?=$id?>">Teklif hazırlığına geç →</a><?php elseif(surveyAdmin()&&!$locked):?><form method="post" onsubmit="return confirm('Ekspertiz onaylansın mı? Sonrasında düzenlenemez.')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="id" value="<?=$id?>"><button class="sv-btn" name="action" value="status">Kontrol ettim, ekspertizi onayla</button></form><?php endif;?></section>
<?php if($linkedQuotes):?><section class="sv-card"><h2>Bağlı teklifler</h2><div class="sv-actions"><?php foreach($linkedQuotes as $linked):?><a class="sv-btn secondary" href="view.php?id=<?=(int)$linked['id']?>"><?=h($linked['quote_no'])?> →</a><?php endforeach;?></div><p>Mevcut teklif ve sözleşmeler revizyonla değiştirilmez.</p></section><?php endif;?>
<?php if(surveyAdmin()&&$workspaceReady):?><section class="sv-card"><h2>Dosya işlemleri</h2>
<?php if($survey['status']==='approved'):?><form method="post" class="sv-grid"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="revise"><label>Revizyon gerekçesi<textarea name="reason" required maxlength="1000" rows="2"></textarea></label><div class="sv-help">Yeni taslak açılır; eski onay, envanter ve fotoğraflar korunur. Envanter yeni taslağa kopyalanır. Kaynak fotoğraflar önceki dosyadan incelenir.</div><button class="sv-btn">Yeni revizyon taslağı aç</button></form><?php endif;?>
<?php $nextStatus=in_array($survey['status'],['draft','assigned'],true)?'cancelled':($survey['status']==='cancelled'?'draft':($survey['status']==='approved'&&$linkedQuotes?'completed':''));if($nextStatus):?><form method="post" class="sv-lifecycle" onsubmit="return confirm('Ekspertiz durumu güncellensin mi?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="lifecycle"><input type="hidden" name="next_status" value="<?=h($nextStatus)?>"><button class="sv-btn secondary"><?=$nextStatus==='cancelled'?'Ekspertizi iptal et':($nextStatus==='draft'?'Yeniden planlamaya aç':'Ekspertizi tamamlandı işaretle')?></button></form><?php endif;?>
<?php $history=db()->prepare('SELECT event_type,detail,created_at FROM ek_survey_activity WHERE survey_id=? ORDER BY id DESC LIMIT 30');$history->execute([$id]);$events=$history->fetchAll();?><details class="sv-history"><summary>İşlem geçmişi</summary><?php foreach($events as $event):?><p><b><?=h(svOpsEventLabel($event['event_type']))?></b> · <?=h($event['created_at'])?><br><?=h($event['detail'])?></p><?php endforeach;?><?php if(!$events):?><p>Bu sürüm öncesindeki işlemlerin geçmiş kaydı yok.</p><?php endif;?></details></section><?php endif;?>
<?php if(surveyAdmin()):?><details class="sv-card sv-create" id="ekspertiz-sil" style="border:1px solid #fecaca"><summary style="color:#991b1b">Ekspertizi kalıcı sil</summary><p>Bu işlem ekspertiz kaydını, eşya envanterini ve ilişkili fotoğraf kayıtlarını kalıcı olarak siler. Müşteri, teklif ve sözleşmeler silinmez. İşlem geri alınamaz.</p><form method="post" onsubmit="return confirm('Ekspertiz #<?=$id?> ve bağlı eşyalar ile fotoğraflar KALICI olarak silinecek. Devam edilsin mi?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="delete_survey"><input type="hidden" name="confirm_delete" value="DELETE"><button type="submit" class="sv-btn" style="background:#991b1b">Ekspertizi Kalıcı Sil</button></form></details><?php endif;?>
</div><?php endif;?>
</div>
<script>
(function(){
 const create=document.getElementById('sv-create');function openCreate(){if(create&&location.hash==='#sv-create')create.open=true;}window.addEventListener('hashchange',openCreate);openCreate();
 const catalog=<?=json_encode(array_column(svCatalogItems(),1,0),JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 const item=document.getElementById('sv-item-name'),volume=document.getElementById('sv-unit-volume');
 if(item&&volume)item.addEventListener('change',()=>{if(Object.prototype.hasOwnProperty.call(catalog,item.value)){volume.value=String(catalog[item.value]);window.ekVolumeInit?.(volume.form)}});
 const aiPanel=document.getElementById('sv-ai-panel'),aiResult=document.getElementById('sv-ai-result');
 document.querySelectorAll('.sv-ai-trigger').forEach(button=>button.addEventListener('click',async()=>{
  if(!await window.ekConfirm('Bu fotoğraf, eşya tespiti için harici yapay zekâ hizmetine gönderilecek. Devam edilsin mi?'))return;
  aiPanel.hidden=false;aiResult.textContent='Fotoğraf analiz ediliyor…';button.disabled=true;
  try{
   const body=new FormData();body.set('csrf',<?=json_encode(csrf())?>);body.set('photo_id',button.dataset.photoId);
   const res=await fetch('ekspertiz-ai.php',{method:'POST',body,credentials:'same-origin'});
   const data=await res.json();if(!res.ok||!data.ok)throw new Error(data.error||'Analiz başarısız.');
   aiResult.replaceChildren();if(!data.items.length){aiResult.textContent='Fotoğrafta güvenilir eşya önerisi bulunamadı.';return;}
   const note=document.createElement('p');note.textContent=data.items.length+' öneri bulundu. Her eşya için değerleri kontrol edip ayrı ayrı kaydedin.';aiResult.append(note);
   for(const item of data.items){
    const form=document.createElement('form');form.method='post';form.className='sv-ai-item';
    for(const [name,value] of Object.entries({csrf:<?=json_encode(csrf())?>,id:<?=json_encode($id)?>,action:'item'})){const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;form.append(input);}
    for(const [name,value,label,type] of [['room',button.dataset.room,'Oda','text'],['item_name',item.name,'Eşya','text'],['quantity',item.quantity,'Adet','number'],['unit_volume',item.unit_volume,'Birim m³','number']]){
     const wrap=document.createElement('label');wrap.textContent=label;const input=document.createElement('input');input.name=name;input.type=type;input.value=value;input.required=true;if(name==='quantity'){input.min='1';input.max='500';}if(name==='unit_volume'){input.min='0';input.max='100';input.step='0.001';input.setAttribute('data-ek-volume','');}wrap.append(input);form.append(wrap);
    }
    const save=document.createElement('button');save.className='sv-btn';save.type='submit';save.textContent='Kontrol ettim, envantere ekle';form.append(save);aiResult.append(form);window.ekVolumeInit?.(form);
   }
  }catch(e){aiResult.textContent=e.message;}finally{button.disabled=false;}
 }));
 const photo=document.getElementById('sv-photo-input'),preview=document.getElementById('sv-photo-preview');let previousUrl=null;
 if(photo&&preview)photo.addEventListener('change',()=>{if(previousUrl)URL.revokeObjectURL(previousUrl);const f=photo.files&&photo.files[0];if(!f||!f.type.startsWith('image/')){preview.hidden=true;return;}previousUrl=URL.createObjectURL(f);preview.src=previousUrl;preview.hidden=false;});
})();
</script>
<script src="assets/survey-workspace.js?v=363301" defer></script>
<script src="assets/survey-catalog.js?v=363301" defer></script>
<?php bottom();
