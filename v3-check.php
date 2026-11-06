<?php
require __DIR__.'/inc/core.php';
auth();
$pdo=db();
$tables=['users','customers','quotes','templates','transport_modes','email_templates','company_settings','quote_details'];
$results=[];
foreach ($tables as $table) {
    try {
        $s = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?'
        );
        $s->execute([$table]);
        $results[$table] = ((int)$s->fetchColumn() > 0);
    } catch (Throwable $e) {
        $results[$table] = null;
        error_log('V3 tablo kontrol hatası: ' . $e->getMessage());
    }
}
require __DIR__.'/inc/layout.php';top('V3 Hazirlik Kontrolu');
?>
<div class="card"><h2>EKŞİOĞLU V3 — Kurulum Öncesi Kontrol</h2>
<p>Bu sayfa sadece veritabanı tablolarının varlığını kontrol eder. Hiçbir kayıt eklemez, değiştirmez veya silmez.</p>
<table style="width:100%;border-collapse:collapse"><thead><tr><th style="text-align:left;padding:9px">Tablo</th><th style="text-align:left;padding:9px">Durum</th></tr></thead><tbody>
<?php foreach ($results as $table => $ok): ?>
<tr>
    <td style="padding:9px;border-top:1px solid #ddd">
        <?= h($table) ?>
    </td>
    <td style="padding:9px;border-top:1px solid #ddd">
        <?php if ($ok === null): ?>
            <span style="color:#b45309">Kontrol hatası</span>
        <?php elseif ($ok): ?>
            <span style="color:#15803d">Mevcut</span>
        <?php else: ?>
            <span style="color:#b45309">Bulunamadı</span>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody></table>
<p><strong>PHP sürümü:</strong> <?=h(PHP_VERSION)?> | <strong>ZIP:</strong> <?=class_exists('ZipArchive')?'Etkin':'Kapalı'?></p>
<p><strong>V3 için gerekli quote_details tablosu:</strong> <?=$results['quote_details']?'Mevcut':'Henüz oluşturulmamış'?></p>
<p><small>Bu kontrol sayfası V3 özelliklerini etkinleştirmez. Sonuçlara göre uyumlu güncelleme hazırlanacaktır.</small></p>
</div><?php bottom();
