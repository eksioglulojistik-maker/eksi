<?php
require __DIR__.'/inc/v11.php';
auth();v11_require();
$id=(int)($_GET['id']??$_POST['id']??0);
$q=$id?v11_quote($id):null;
if(!$q){http_response_code(404);exit('Teklif bulunamadı.');}
$s=db()->prepare('SELECT id,body,language FROM v11_contracts WHERE quote_id=?');$s->execute([$id]);$contract=$s->fetch();
if(!$contract){http_response_code(404);exit('Önce sözleşme taslağını kaydedin.');}
$hash=hash('sha256',$contract['language']."\n".$contract['body']);$displayHash=ekDisplayHash(ekLoadContractDisplay(db(),$id));
$err='';$ok='';

try{
 if($_SERVER['REQUEST_METHOD']==='POST'){
  verify();
  if(!v111_is_admin())throw new RuntimeException('İmza kaydetme yetkisi yalnızca yöneticidedir.');
  if(($_POST['consent']??'')!=='yes')throw new RuntimeException('Müşterinin imza onayını işaretleyin.');
  $name=trim((string)($_POST['signer_name']??''));
  if($name===''||mb_strlen($name)>190)throw new RuntimeException('İmzalayan adını girin.');
  $upload=$_FILES['signature_png']??null;
  if(!is_array($upload)||($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('İmza dosyası yüklenemedi. PHP yükleme sınırını ve sunucu güvenlik kaydını kontrol edin.');
  if(($upload['size']??0)<250||$upload['size']>110000||!is_uploaded_file($upload['tmp_name']))throw new RuntimeException('İmza dosyası geçersiz veya çok büyük.');
  $bin=file_get_contents($upload['tmp_name']);
  if($bin===false||substr($bin,0,8)!=="\x89PNG\r\n\x1a\n")throw new RuntimeException('Geçerli PNG imzası alınamadı.');
  $dim=@getimagesizefromstring($bin);
  if(!$dim||$dim[2]!==IMAGETYPE_PNG||$dim[0]>1600||$dim[1]>700)throw new RuntimeException('İmza boyutları geçersiz.');
  $expected=$_POST['contract_hash']??'';
  if(!is_string($expected)||!hash_equals($hash,$expected))throw new RuntimeException('Sözleşme değişmiş. Sayfayı yeniden açın.');
  ekStoreContractSignature(db(),$id,$expected,$name,$bin,(int)$_SESSION['uid'],hash('sha256',($_SERVER['REMOTE_ADDR']??'').'|'.DB_PASS),null,is_string($_POST['display_hash']??null)?$_POST['display_hash']:null);
  $ok='Müşteri imzası sözleşmeye kaydedildi.';
 }
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$err=$e->getMessage();}
$stored=null;
try{
 $st=db()->query("SHOW TABLES LIKE 'ek_contract_signatures'");
 if($st->fetchColumn()){
  $st=db()->prepare('SELECT signer_name,signed_at,contract_hash FROM ek_contract_signatures WHERE quote_id=?');$st->execute([$id]);$stored=$st->fetch();
 }
}catch(Throwable $e){}
require __DIR__.'/inc/layout.php';top('Müşteri İmzası');
?>
<style>
.eks-sign-wrap{max-width:940px;margin:20px auto}.eks-sign-hero{background:linear-gradient(110deg,#112b4c,#22537e);color:white;padding:32px;border-radius:17px}.eks-sign-hero h1{margin:7px 0;font-size:27px}.eks-sign-hero p{color:#d4e2f0;margin:0}.eks-sign-card{background:white;border:1px solid #dae4ee;border-radius:17px;padding:27px;margin-top:18px;box-shadow:0 10px 32px #16345410}.eks-sign-card label{font-weight:700;color:#23425f;display:block;margin-bottom:8px}.eks-sign-card input[type=text]{width:100%;max-width:470px;padding:12px;border:1px solid #bdcddd;border-radius:9px}.eks-sign-pad{border:2px dashed #9fb6ce;background:#fbfdff;border-radius:13px;margin:14px 0;touch-action:none;overflow:hidden}.eks-sign-pad canvas{display:block;width:100%;height:260px;touch-action:none;cursor:crosshair}.eks-sign-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.eks-sign-btn{border:0;border-radius:9px;background:#174b7c;color:white;padding:12px 20px;font-weight:750;cursor:pointer;text-decoration:none;display:inline-block}.eks-sign-btn.alt{background:#e9eff5;color:#23476a}.eks-sign-note{font-size:13px;color:#61758b;line-height:1.7}.eks-sign-state{padding:12px 16px;border-radius:10px;background:#edf5fc;margin:14px 0}.eks-sign-error{background:#fff0f0;color:#a52222;padding:13px;border-radius:10px}.eks-sign-ok{background:#e8f8ee;color:#176b41;padding:13px;border-radius:10px}@media(max-width:650px){.eks-sign-hero,.eks-sign-card{padding:19px}.eks-sign-pad canvas{height:210px}}
</style>
<div class="eks-sign-wrap"><div class="eks-sign-hero"><small>EKŞİOĞLU · INTERNATIONAL MOVERS</small><h1>Müşteri imzası</h1><p><?=h($q['quote_no'])?> · <?=h($q['name'])?></p></div>
<?php if($err):?><p class="eks-sign-error"><?=h($err)?></p><?php endif;?><?php if($ok):?><p class="eks-sign-ok"><?=h($ok)?></p><?php endif;?>
<div class="eks-sign-card">
<?php if($stored):?><div class="eks-sign-state"><?=hash_equals($hash,$stored['contract_hash'])?'✓ Geçerli imza kayıtlı':'⚠ Sözleşme değiştiği için eski imza artık geçerli değil'?> · <?=h($stored['signer_name'])?> · <?=h($stored['signed_at'])?></div><?php endif;?>
<p class="eks-sign-note">Müşteri sözleşmenin tamamını inceleyip kabul ettikten sonra aşağıdaki alana parmağıyla, dokunmatik kalemle veya fareyle imza atabilir. Bu ekran yalnızca yönetici oturumunda kaydeder; müşteriye uzaktan imza bağlantısı göndermez.</p>
<p><a class="eks-sign-btn alt" target="_blank" rel="noopener" href="v11-print.php?id=<?=$id?>&type=contract">Sözleşmenin tamamını incele ↗</a></p>
<?php if(v111_is_admin()&&!$stored):?>
<form method="post" enctype="multipart/form-data" id="eks-sign-form"><noscript><p class="eks-sign-error">İmza kaydı için tarayıcınızda JavaScript etkin olmalıdır.</p></noscript>
<input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="contract_hash" value="<?=h($hash)?>"><input type="hidden" name="display_hash" value="<?=h($displayHash)?>"><input type="file" name="signature_png" id="eks-sign-data" accept="image/png" hidden>
<label for="eks-sign-name">İmzalayan müşteri adı soyadı</label><input type="text" name="signer_name" id="eks-sign-name" value="<?=h($stored['signer_name']??$q['name'])?>" maxlength="190" required>
<label style="margin-top:18px">Dokunmatik imza alanı</label><div class="eks-sign-pad"><canvas id="eks-sign-canvas" aria-label="Parmak, kalem veya fare ile imza alanı"></canvas></div>
<div class="eks-sign-actions"><button type="button" class="eks-sign-btn alt" id="eks-sign-clear">İmzayı temizle</button></div>
<p class="eks-sign-note"><label><input type="checkbox" name="consent" value="yes" required> Müşteri sözleşmeyi inceledi, imzanın kaydedilmesine onay verdi ve imzayı kendi eliyle attı.</label></p>
<div class="eks-sign-actions"><button type="submit" class="eks-sign-btn">İmzayı sözleşmeye kaydet</button><a class="eks-sign-btn alt" href="v11-documents.php?id=<?=$id?>">Sözleşmelere dön</a></div>
</form>
<?php else:?><p class="eks-sign-note">Mevcut imza korunur. Yeni imza için yeni teklif revizyonu kullanılmalıdır; imza kaydı yönetici yetkisi gerektirir.</p><?php endif;?>
</div></div>
<script>
(()=>{const canvas=document.getElementById('eks-sign-canvas');if(!canvas)return;const ctx=canvas.getContext('2d'),form=document.getElementById('eks-sign-form'),hidden=document.getElementById('eks-sign-data');let drawing=false,strokes=0;
function setup(){const ratio=Math.min(window.devicePixelRatio||1,2);canvas.width=Math.round(canvas.clientWidth*ratio);canvas.height=Math.round(canvas.clientHeight*ratio);ctx.setTransform(ratio,0,0,ratio,0,0);ctx.fillStyle='#fff';ctx.fillRect(0,0,canvas.clientWidth,canvas.clientHeight);ctx.strokeStyle='#153c67';ctx.lineWidth=2.6;ctx.lineCap='round';ctx.lineJoin='round';strokes=0;}
function pos(e){const r=canvas.getBoundingClientRect();return{x:e.clientX-r.left,y:e.clientY-r.top};}
canvas.addEventListener('pointerdown',e=>{e.preventDefault();drawing=true;canvas.setPointerCapture(e.pointerId);const p=pos(e);ctx.beginPath();ctx.moveTo(p.x,p.y);ctx.lineTo(p.x+.1,p.y+.1);ctx.stroke();strokes++;});
canvas.addEventListener('pointermove',e=>{if(!drawing)return;e.preventDefault();const p=pos(e);ctx.lineTo(p.x,p.y);ctx.stroke();strokes++;});
for(const event of ['pointerup','pointercancel','lostpointercapture'])canvas.addEventListener(event,()=>drawing=false);
document.getElementById('eks-sign-clear').addEventListener('click',setup);
form.addEventListener('submit',e=>{if(strokes<6){e.preventDefault();window.ekAlert('Lütfen müşteri imzasını çizerek tamamlayın.');return;}e.preventDefault();canvas.toBlob(blob=>{if(!blob||blob.size>110000){window.ekAlert('İmza boyutu çok büyük. Temizleyip yeniden deneyin.');return;}try{const dt=new DataTransfer();dt.items.add(new File([blob],'signature.png',{type:'image/png'}));hidden.files=dt.files;HTMLFormElement.prototype.submit.call(form);}catch(err){window.ekAlert('İmza dosyası hazırlanamadı: '+err.message);}},'image/png');});
setup();
})();
</script>
<?php bottom(); ?>
