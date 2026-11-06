<?php
require __DIR__.'/inc/core.php';auth();
if(!ekAdmin()){http_response_code(403);exit('Yönetici yetkisi gerekir.');}
require __DIR__.'/inc/survey-operations.php';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){verify();try{svOpsInstall(db());redirect('ekspertiz.php?workspace_ready=1');}catch(Throwable $e){$error='Saha altyapısı hazırlanamadı. Sunucu veritabanı izinlerini kontrol edin.';}}
require __DIR__.'/inc/layout.php';top('Ekspertiz Saha Altyapısı');
?><section class="panel"><h1>Ekspertiz saha altyapısı</h1><p>Kat/asansör ve hizmet bilgileri ile revizyon geçmişi için iki yeni tablo oluşturulur. Mevcut müşteri, ekspertiz, teklif ve sözleşme tabloları değiştirilmez. Kurulumdan önce güncelleyici yedeğini saklayın.</p><?php if($error):?><p role="alert"><?=h($error)?></p><?php endif;?><?php if(svOpsReady(db())):?><p>Altyapı hazır.</p><a href="ekspertiz.php">Ekspertizlere dön</a><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button type="submit" class="btn">Saha altyapısını hazırla</button></form><?php endif;?></section><?php bottom(); ?>
