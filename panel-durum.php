<?php
require __DIR__.'/inc/core.php';auth();
$pdo=db();
$role=strtolower((string)($_SESSION['role']??''));
if(!in_array($role,['admin','administrator'],true)){
  try{$q=$pdo->prepare('SELECT role FROM users WHERE id=?');$q->execute([$_SESSION['uid']??0]);$role=strtolower((string)$q->fetchColumn());}catch(Throwable $e){}
}
if(!in_array($role,['admin','administrator'],true)){http_response_code(403);exit('Yalnızca yönetici erişebilir.');}
$modules=[
 ['Teklifler','quotes.php'],['Müşteriler','customers.php'],
 ['Taşınma Takvimi','v11-calendar.php'],['Sözleşmeler','v11-documents.php'],
 ['İçerik Şablonları','templates.php'],['Şablon Düzenleyici','v119-template-edit.php'],
 ['Teklif Belgesi','inc/quote-document.php'],['Metin İşleme','inc/quote-richtext.php'],
 ['Güncelleme Yöneticisi','update-manager.php']
];
$legacy=['v11-center.php','template-studio.php','v11-setup.php','v1113-setup.php','v117-template-setup.php','v119-setup.php','v3-check.php'];
require __DIR__.'/inc/layout.php';top('Panel Durumu');
?>
<style>.pd-wrap{max-width:1080px}.pd-box{background:#fff;border:1px solid #dde5ef;border-radius:14px;padding:20px;margin:14px 0}.pd-table{width:100%;border-collapse:collapse}.pd-table td,.pd-table th{padding:11px;text-align:left;border-bottom:1px solid #e8edf3}.pd-table th{font-size:12px;color:#64748b}.pd-ok{color:#167346;font-weight:700}.pd-bad{color:#a33b31;font-weight:700}.pd-muted{color:#63758b;font-size:13px;line-height:1.6}.pd-pill{background:#edf3fa;border-radius:7px;padding:4px 8px}</style>
<div class="pd-wrap"><h1>Panel Durumu ve Bakım</h1><p class="pd-muted">Salt okunur kontrol. Bu ekran veritabanını, teklifleri veya dosyaları değiştirmez.</p>
<section class="pd-box"><h2>Temel Modüller</h2><table class="pd-table"><thead><tr><th>Modül</th><th>Dosya</th><th>Durum</th></tr></thead><tbody>
<?php foreach($modules as [$name,$file]):$exists=is_file(__DIR__.'/'.$file);?><tr><td><?=h($name)?></td><td><code><?=h($file)?></code></td><td class="<?=$exists?'pd-ok':'pd-bad'?>"><?=$exists?'Mevcut':'Eksik'?></td></tr><?php endforeach;?></tbody></table></section>
<section class="pd-box"><h2>Eski / Bakım Ekranları</h2><p class="pd-muted">Bu dosyalar geçmiş sürümlerin bağlantıları veya kurulum bağımlılıkları için korunmuştur. Günlük yönetim menüsünden çıkarılmıştır; güvenli olduğu doğrulanmadan otomatik silinmez.</p><table class="pd-table"><thead><tr><th>Dosya</th><th>Durum</th></tr></thead><tbody>
<?php foreach($legacy as $file):?><tr><td><code><?=h($file)?></code></td><td><?=is_file(__DIR__.'/'.$file)?'Korunuyor':'Bulunamadı'?></td></tr><?php endforeach;?></tbody></table></section>
<section class="pd-box"><h2>Temizlik İlkeleri</h2><p class="pd-muted">1. Günlük kullanımda tek teklif şablonları ekranı kullanılır. 2. Eski tasarım stüdyosu ve V11 araç merkezi menüden çıkarılır. 3. Teklifler, müşteriler, envanter, sözleşmeler ve şablonlar silinmez. 4. Kurulum dosyaları yalnızca sunucu bağımlılıkları kontrol edildikten sonra ayrı bir bakımda kaldırılmalıdır.</p><p><a href="settings-hub.php">← Ayarlara dön</a></p></section></div>
<?php bottom(); ?>
