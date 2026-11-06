<?php
require __DIR__.'/inc/v11.php';auth();v11_admin();
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify();
 try{db()->exec("CREATE TABLE IF NOT EXISTS quote_archives (quote_id INT NOT NULL PRIMARY KEY, archived_by INT NULL, archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_archived_at (archived_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");$message='Silinen teklifler alanı hazır. Mevcut teklifler korunmuştur.';}
 catch(Throwable $e){$error='Kurulum başarısız. Veritabanı yetkilerini kontrol edin.';}
}
require __DIR__.'/inc/layout.php';top('V11.13 Kurulum');
?>
<div class="panel" style="max-width:760px;margin:30px auto;padding:26px"><h1>V11.13 · Güvenli Teklif Silme</h1><p>Bu kurulum yalnızca tekliflerin arşiv durumunu tutan yeni bir tablo oluşturur. Mevcut teklifleri veya bağlı müşteri, envanter, sözleşme kayıtlarını silmez.</p><?php if($message):?><p style="color:green"><?=h($message)?></p><?php endif;?><?php if($error):?><p style="color:#b42318"><?=h($error)?></p><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button class="btn" type="submit">Güvenli Silme Alanını Kur</button></form><p style="margin-top:20px"><a href="quotes.php">← Teklifler sayfasına dön</a></p></div>
<?php bottom();
