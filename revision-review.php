<?php
require __DIR__.'/inc/core.php';auth();require_once __DIR__.'/inc/permissions.php';ek_require_admin();
require_once __DIR__.'/inc/revision-review.php';
if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');exit('Bu ekran yalnız görüntüleme içindir.');}
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);$review=null;$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){verify();try{
 if(!$id)throw new RuntimeException('Geçersiz inceleme isteği.');
 $action=$_POST['action']??'';
 if($action==='revoke_offer')ekRevokeRevision(db(),$id,(int)$_SESSION['uid']);
 elseif($action==='publish_offer'&&is_string($_POST['fingerprint']??null))ekPublishRevision(db(),$id,$_POST['fingerprint'],(int)$_SESSION['uid']);
 elseif($action==='save_finance_review'&&is_string($_POST['fingerprint']??null)&&is_string($_POST['note']??null))ekSaveRevisionFinanceReview(db(),$id,$_POST['fingerprint'],(int)$_SESSION['uid'],$_POST['note']);
 else throw new RuntimeException('Geçersiz inceleme isteği.');
 redirect('revision-review.php?id='.(int)$id.'&reviewed=1');
}catch(Throwable $e){http_response_code(409);error_log('Finance review failed: '.$e->getMessage());$error=$e instanceof PDOException?'İnceleme kaydedilemedi. Kayıtlar değiştirilmedi.':$e->getMessage();}}

try{if(!$id||$id<1)throw new RuntimeException('Geçerli bir revizyon seçin.');$review=ekRevisionReview(db(),$id);}
catch(Throwable $e){http_response_code(409);error_log('Revision review: '.$e->getMessage());$error=$e instanceof PDOException?'İnceleme bilgileri okunamadı.':$e->getMessage();}
require __DIR__.'/inc/layout.php';top('Revizyon Karşılaştırması');
?>
<section class="card"><h1>Revizyon Karşılaştırması</h1>
<?php if($error):?><p role="alert"><?=h($error)?></p><?php else:?>
<p>Kaynak teklif ve revizyon ilişkisi. Finans incelemesi ve müşteri sunumu aşağıdaki adımlarla yönetilir. Tahsilatlar taşınmaz; kabul edilen revizyon ayrı ve imzasız bir sözleşme taslağıyla ilişkilendirilir.</p>
<?php if(!$review['payments_ready']):?><p role="alert">Tahsilat tablosu bulunamadı. Ödeme bilgisi doğrulanamadı; sıfır kabul edilmemelidir.</p><?php endif;?>
<div class="table-wrap"><table><thead><tr><th scope="col">Bilgi</th><th scope="col">Kaynak teklif</th><th scope="col">Revizyon</th></tr></thead><tbody>
<tr><th scope="row">Teklif</th><?php foreach($review['items'] as $item):?><td><a href="quote.php?id=<?=(int)$item['quote']['id']?>"><?=h($item['quote']['quote_no'])?></a></td><?php endforeach;?></tr>
<?php foreach(['status'=>'Durum','origin'=>'Çıkış adresi','destination'=>'Varış adresi','volume'=>'Hacim (m³)','total'=>'Teklif bedeli'] as $key=>$label):?>
<tr><th scope="row"><?=h($label)?></th><?php foreach($review['items'] as $item):$q=$item['quote'];?><td><?= $key==='total'?money($q['total'],$q['currency']):h($key==='status'?statusText(($key==='volume'?number_format((float)$q[$key],2,',','.'):$q[$key])):($key==='volume'?number_format((float)$q[$key],2,',','.'):$q[$key])) ?></td><?php endforeach;?></tr>
<?php endforeach;?>
<tr><th scope="row">Müşteri</th><?php foreach($review['items'] as $item):?><td><?=h($item['customer']['name']??'Müşteri bulunamadı')?></td><?php endforeach;?></tr>
<tr><th scope="row">Mevcut imza</th><?php foreach($review['items'] as $item):?><td><?=$item['signed']?'İmza kaydı var; korunur':'İmza kaydı yok'?></td><?php endforeach;?></tr>
<tr><th scope="row">Kayıtlı tahsilatlar</th><?php foreach($review['items'] as $item):?><td><?php if(!$review['payments_ready']):?>Doğrulanamadı<?php elseif(!$item['payments']):?>Kayıt yok<?php else:foreach($item['payments'] as $payment):?><p><?=money($payment['cents']/100,$payment['currency'])?> · <?=(int)$payment['count']?> kayıt</p><?php endforeach;endif;?></td><?php endforeach;?></tr>
</tbody></table></div>
<?php if(empty($review['transition']['revision_activated'])):?><h2>Finans mutabakatı incelemesi</h2>
<?php $f=$review['finance']; ?>
<p>Kaynak teklif yürürlükte kalır. Aşağıdaki hesap revizyonun kabul edilmesi halinde oluşabilecek bakiyeyi gösterir; muhasebe kaydı veya iade talimatı oluşturmaz.</p>
<dl><dt>Kaynak teklife göre fiyat farkı</dt><dd><?=$f['difference_cents']===null?'Farklı para birimleri; karşılaştırılamaz':money($f['difference_cents']/100,$f['currency'])?></dd><dt>Zincirdeki önceki tahsilatlar (aynı para birimi)</dt><dd><?=money($f['prior_paid_cents']/100,$f['currency'])?></dd></dl>
<?php if($f['blocks']):?><div role="alert"><p>Bakiye hesaplaması doğrulanamadı:</p><ul><?php foreach($f['blocks'] as $block):?><li><?=h($block)?></li><?php endforeach;?></ul></div>
<?php else:?><dl><dt>Revizyon kabul edilirse kalan ödeme</dt><dd><?=money($f['additional_cents']/100,$f['currency'])?></dd><dt>Olası iade gereksinimi</dt><dd><?=money($f['refund_cents']/100,$f['currency'])?></dd></dl><?php endif;?>
<?php if($f['has_signature']):?><p>Mevcut imzalı belge korunur. Finans incelemesi yeni sözleşme veya müşteri kabulü yerine geçmez.</p><?php endif;?>
<?php if($review['reviewed']):?><p role="status">Finans incelemesi güncel veriler için kaydedilmiş. Teklifin yürürlüğü değiştirilmedi.</p>
<?php elseif($review['last_review']):?><p role="alert">Önceki incelemeden sonra bilgiler değişti; yeniden inceleme gerekir.</p><?php endif;?>
<?php if(!$f['blocks']&&!$review['reviewed']):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="save_finance_review"><input type="hidden" name="fingerprint" value="<?=h($f['fingerprint'])?>"><label>İnceleme açıklaması<textarea name="note" required minlength="5" maxlength="500" rows="3"></textarea></label><p><button type="submit">Finans incelemesini kaydet</button></p></form><?php endif;?>
<?php endif;?>
<?php $transition=$review['transition'];$offer=$transition['revision_offer']??[];?>
<h2>Müşteriye sunum ve geçerli teklif</h2>
<?php if(!empty($transition['revision_effective_child_id'])):?><p role="status">Bu revizyon tarihsel kayıttır. <a href="revision-review.php?id=<?=(int)$transition['revision_effective_child_id']?>">Geçerli revizyonu aç</a></p>
<?php elseif(!empty($transition['revision_activated'])):?><p role="status">Müşteri bu revizyonu kabul etti. Güncel finans kaydı bu tekliftir. Kaynak sözleşme ve imzası tarihsel kayıtta korunur.</p><p><a href="quote-payments.php?id=<?=$id?>">Güncel bakiye</a> · <a href="v11-documents.php?id=<?=$id?>">Yeni sözleşme taslağı</a> · <a href="move-file.php?id=<?=$id?>">Taşınma dosyası</a></p>
<?php elseif(($offer['state']??'')==='offered'):?>
<p>Sunum açık. Müşteri kabul edene kadar kaynak teklif geçerlidir. Düzenlemek veya süresi dolan bağlantıyı yenilemek için önce sunumu iptal edin.</p>
<?php try{$offerLink=ekRevisionOfferLink(db(),$id);?><p><a href="<?=h($offerLink)?>" target="_blank" rel="noopener noreferrer">Müşteri revizyon sunumunu görüntüle</a> · <a href="v112-mail.php?id=<?=$id?>">E-posta ile gönder</a></p><?php }catch(Throwable $ignored){?><p>Sunumun süresi dolmuş.</p><?php }?>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="revoke_offer"><button type="submit">Sunumu iptal et</button></form>
<?php elseif($review['reviewed']&&!$f['blocks']):?>
<p>Bu işlem müşteri için yedi güne kadar geçerli bir sunum bağlantısı hazırlar ve revizyonu düzenlemeye kapatır. E-posta göndermez ve teklifi kabul etmez.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="publish_offer"><input type="hidden" name="fingerprint" value="<?=h($f['fingerprint'])?>"><button type="submit">Müşteri sunumunu hazırla</button></form>
<?php else:?><p>Müşteri sunumu için güncel ve hatasız finans incelemesi gerekir.</p><?php endif;?>
<p>Tahsilatlar kendi tekliflerinde ve para birimlerinde gösterilir. Revizyona aktarılmadı; kur dönüşümü veya teklifler arası mahsup yapılmadı.</p>
<p><a href="quote.php?id=<?=(int)$id?>">Revizyon taslağına dön</a> · <a href="quote-payments.php?id=<?=(int)$review['items'][0]['quote']['id']?>">Kaynak teklif tahsilatları</a></p>
<?php endif;?></section>
<?php bottom(); ?>
