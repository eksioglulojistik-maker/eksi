<?php
require __DIR__.'/inc/core.php';require_once __DIR__.'/inc/revision-transition.php';require_once __DIR__.'/inc/quote-richtext.php';
header('Referrer-Policy: no-referrer');header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');
if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');exit('Geçersiz yöntem.');}
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);$token=$_GET['token']??'';
if(!$id||!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token)){http_response_code(404);exit('Revizyon bağlantısı geçersiz.');}
$p=db();$m=ekRevisionMeta($p,$id);$o=$m['revision_offer']??[];
if(empty($o['nonce'])||!hash_equals(ekRevisionOfferToken($id,$o,DB_PASS),$token)){http_response_code(404);exit('Revizyon bağlantısı geçersiz.');}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify();try{if(!is_string($_POST['decision']??null)||($_POST['confirmation']??'')!=='1')throw new RuntimeException('Lütfen revizyon yanıtını doğrulayın.');ekRevisionDecision($p,$id,$token,DB_PASS,$_POST['decision']);redirect('customer-revision.php?id='.(int)$id.'&token='.$token);}
 catch(Throwable $e){http_response_code(409);error_log('Revision customer decision: '.$e->getMessage());$error='Yanıt kaydedilemedi. Sunum değişmiş veya kapanmış olabilir; şirket yetkilisiyle iletişime geçin.';}
}
$m=ekRevisionMeta($p,$id);$o=$m['revision_offer'];$state=$o['state'];$open=$state==='offered'&&$o['expires_at']>date('Y-m-d H:i:s');
if($state==='revoked'||($state==='offered'&&!$open)){http_response_code(410);exit('Revizyon sunumu iptal edilmiş veya süresi dolmuş.');}
if($open){$p->beginTransaction();try{$f=ekRevisionFinance($p,$id);$open=!$f['blocks']&&hash_equals($o['fingerprint'],$f['fingerprint']);$p->commit();}catch(Throwable $e){if($p->inTransaction())$p->rollBack();$open=false;}}
$q=$o['display'];$detail=$o['details'];$fin=$o['finance'];
?><!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Teklif Revizyonu · EKŞİOĞLU</title><link rel="stylesheet" href="assets/style.css?v=35001"></head><body class="public-offer"><main class="public-wrap">
<section class="card"><h1>Taşınma Teklifi Revizyonu</h1><p>Kaynak teklif: <?=h($o['parent_no'])?> · Revizyon: <?=h($q['quote_no'])?></p>
<p>Bu revizyon kabul edilirse taşıma hizmetinin güncel teklif bedeli ve koşulları aşağıdaki teklife göre izlenir. Kaynak teklif, önceki sözleşme ve imza geçmişte saklanır. Bu yanıt eski belgedeki imzayı yeni sözleşmeye aktarmaz; yeni sözleşme ayrıca incelenir ve imzalanır.</p>
<dl><dt>Yeni toplam teklif bedeli</dt><dd><?=money($fin['total_cents']/100,$fin['currency'])?></dd><dt>Sunum hazırlanırken kayıtlı önceki tahsilatlar</dt><dd><?=money($fin['paid_cents']/100,$fin['currency'])?></dd><dt>Kalan ödeme</dt><dd><?=money($fin['due_cents']/100,$fin['currency'])?></dd><dt>Olası iade gereksinimi</dt><dd><?=money($fin['refund_cents']/100,$fin['currency'])?></dd></dl><p>İade otomatik yapılmaz; ayrıca değerlendirilir.</p></section>
<?php include __DIR__.'/inc/quote-document.php'; ?>
<section class="card public-decision"><h2>Revizyon yanıtı</h2>
<?php if($error):?><p role="alert"><?=h($error)?></p><?php endif;?>
<?php if($state==='accepted'):?><?php $currentAccount=ekFinancialAccount($p,(int)$id);?><p role="status">Revizyon kabul edildi. <?= $currentAccount['effective_id']===(int)$id?'Güncel teklif budur; yeni sözleşme taslağı şirket tarafından tamamlanacaktır.':'Bu sunum tarihsel kayıttır; daha sonraki bir revizyon yürürlüktedir.' ?></p>
<?php elseif($state==='rejected'):?><p role="status">Revizyon reddedildi. Kaynak teklifin durumu değişmedi.</p>
<?php elseif($open):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><label><input type="checkbox" name="confirmation" value="1" required style="width:auto"> Revizyonun tamamını ve kaynak teklifle ilişkisini okudum; seçtiğim yanıtın kaydedilmesini istiyorum.</label><p><button name="decision" value="accepted" type="submit">Revizyonu Kabul Et</button> <button name="decision" value="rejected" type="submit">Revizyonu Reddet</button></p></form>
<?php else:?><p role="alert">Sunumdan sonra bilgiler değişmiş. Yeni sunum için şirket yetkilisine başvurun.</p><?php endif;?></section></main></body></html>
