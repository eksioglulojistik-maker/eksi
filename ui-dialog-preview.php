<?php
require __DIR__.'/inc/core.php';auth();if(!ekAdmin()){http_response_code(403);exit('Yönetici yetkisi gerekir.');}
require __DIR__.'/inc/layout.php';top('Panel Pencere Örnekleri');
$example=is_string($_GET['example']??null)?$_GET['example']:'';
?><section class="panel" style="max-width:760px;margin:20px auto;background:#fff;border:1px solid #dce6f0;border-radius:16px;padding:24px"><h1>Panel onay pencereleri</h1><p>Bu sayfadaki işlemler örnektir. Müşteri, eşya veya diğer kayıtları değiştirmez.</p>
<?php if(in_array($example,['form','button','link'],true)):?><p role="status">Örnek işlem onaylandı: <?=h($example)?></p><?php endif;?>
<button type="button" class="btn" id="dialog-danger-demo">Eşya kaldırma penceresini göster</button><button type="button" class="btn secondary" id="dialog-info-demo">Bilgilendirme penceresi</button><p id="dialog-demo-result" role="status"></p>
<form method="get" onsubmit="return confirm('Örnek dosya arşivlensin mi? Bu örnek gerçek kayıtları etkilemez.')"><button type="submit" name="example" value="form" class="btn secondary">Form onayı örneği</button></form>
<form method="get"><button type="submit" name="example" value="button" class="btn secondary" onclick="return confirm('Örnek işlem onaylansın mı?')">Düğme onayı örneği</button></form>
<a href="ui-dialog-preview.php?example=link" class="btn secondary" data-ek-confirm="Örnek sayfaya gidilsin mi?" data-ek-confirm-title="Sayfaya geçiş" data-ek-confirm-button="Devam et">Bağlantı onayı örneği</a>
</section><script>
document.getElementById('dialog-info-demo').addEventListener('click',()=>window.ekAlert('Örnek bilgilendirme. Gerçek kayıt değiştirilmedi.'));
document.getElementById('dialog-danger-demo').addEventListener('click',async()=>{const accepted=await window.ekConfirm('TV ünitesi bu odanın envanterinden kaldırılsın mı?',{title:'Eşyayı kaldır',confirmText:'Kaldır',tone:'danger'});document.getElementById('dialog-demo-result').textContent=accepted?'Örnek işlem onaylandı. Gerçek kayıt değiştirilmedi.':'Vazgeçildi. Kayıt değiştirilmedi.';});
</script><?php bottom(); ?>
