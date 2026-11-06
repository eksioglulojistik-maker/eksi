<?php
require __DIR__.'/inc/core.php';auth();require_once __DIR__.'/inc/permissions.php';ek_require_admin();require_once __DIR__.'/inc/permanent-delete.php';
header('Cache-Control: no-store');
if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');exit('Geçersiz yöntem.');}
$kind=$_GET['kind']??'';$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);$plan=null;$error='';
$back=$kind==='quote'?'quotes.php':($kind==='customer'?'customers.php':'v11-documents.php');
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify();try{
  $action=$_POST['action']??'';
  if(!is_string($kind)||!$id||!is_string($_POST['fingerprint']??null)||!is_string($action))throw new RuntimeException('Silme onayı eksik.');
  if($kind==='customer'&&in_array($action,['archive','restore'],true)){
   ekSetCustomerArchive(db(),$id,$action==='archive',$_POST['fingerprint'],(int)$_SESSION['uid']);redirect('customers.php?'.($action==='archive'?'result=archived':'archived=1&result=restored'));
  }
  $deleted=ekPermanentlyDelete(db(),$kind,$id,$_POST['fingerprint'],$action,(int)$_SESSION['uid']);
  redirect($kind==='customer'?'customers.php?result=deleted':($kind==='quote'?'quotes.php?purged=1':'v11-documents.php?id='.(int)$deleted['quote_id'].'&purged=1'));
 }catch(Throwable $e){http_response_code(409);error_log('Permanent deletion: '.$e->getMessage());$error=$e instanceof PDOException?'İşlem tamamlanamadı. Mevcut kayıtlar korundu.':$e->getMessage();}
}
try{if(!is_string($kind)||!$id)throw new RuntimeException('Geçerli bir kayıt seçin.');$plan=ekPermanentDeletePlan(db(),$kind,$id);}catch(Throwable $e){http_response_code(409);error_log('Delete review: '.$e->getMessage());$error=$e instanceof PDOException?'Silme koşulları doğrulanamadı. İşlem kapalı.':$e->getMessage();}
$archived=false;if($plan&&$kind==='customer'){$s=db()->prepare('SELECT '.ekCustomerArchiveCondition().' FROM customers c WHERE c.id=?');$s->execute([$id]);$archived=(bool)$s->fetchColumn();}
require __DIR__.'/inc/layout.php';top('Silme Onayı');
?>
<dialog open class="ek-delete-dialog" id="ek-delete-dialog" aria-labelledby="delete-title"><section class="card ek-delete-confirm" aria-labelledby="delete-title"><h1 id="delete-title">Silme Onayı</h1>
<?php if($error):?><p role="alert"><?=h($error)?></p><?php endif;?>
<?php if($plan):?><h2><?=h($plan['title'])?></h2>
<?php if($plan['related']):?><p>Bağlı kayıtlar</p><ul><?php foreach($plan['related'] as $related):?><li><a href="<?=h($related['url'])?>"><?=h($related['label'])?></a> · <?=$related['protected']?'Korunuyor':($plan['blocks']?'Silinmeye uygun':'Birlikte silinecek')?></li><?php endforeach;?></ul><?php endif;?>
<?php if($plan['blocks']):?><p role="alert">Bu kayıt kalıcı silinemez. Korunan iş geçmişi var:</p><ul><?php foreach($plan['blocks'] as $block):?><li><?=h($block)?></li><?php endforeach;?></ul>
<?php else:?><p>Bu kaydı<?=$plan['children']?' ve yukarıdaki uygun bağlı kayıtları':''?> kalıcı olarak silmek istiyor musunuz? <strong>Bu işlem geri alınamaz.</strong></p><p>Yedekler ve asgari silme işlem izi korunur.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="fingerprint" value="<?=h($plan['fingerprint'])?>"><button name="action" value="delete" type="submit">Evet, kalıcı sil</button></form><?php endif;?>
<?php if($kind==='customer'):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="fingerprint" value="<?=h($plan['fingerprint'])?>"><p><?=$archived?'Müşteriyi yeniden aktif listeye alabilirsiniz.':'Arşivleme müşteriyi aktif listeden gizler; teklifleri, sözleşmeleri ve finans geçmişini korur.'?></p><button name="action" value="<?=$archived?'restore':'archive'?>" type="submit"><?=$archived?'Aktif listeye geri al':'Müşteriyi arşivle'?></button></form>
<?php elseif($plan['blocks']&&$plan['quote_id']):?><form method="post" action="quote-archive.php"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="quote_id" value="<?=(int)$plan['quote_id']?>"><button name="action" value="archive" type="submit">Teklifi arşivle</button><p>Teklif ve bağlı iş geçmişi korunur. Devam eden revizyon geçişleri arşivlenemez.</p></form><?php endif;endif;?>
<p><a href="<?=h($back)?>">Vazgeç / listeye dön</a></p></section></dialog>
<script>(function(){const d=document.getElementById('ek-delete-dialog');if(typeof d.showModal==='function'){d.removeAttribute('open');d.showModal();d.addEventListener('cancel',function(e){e.preventDefault();window.location.assign(<?=json_encode($back,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>);});}})();</script><?php bottom(); ?>
