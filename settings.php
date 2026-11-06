<?php
require __DIR__.'/inc/core.php';
auth();
if (!ekAdmin()) {
    http_response_code(403);
    exit('Yönetici yetkisi gerekir.');
}

$pdo = db();
$fields = [
    'phone' => 'Telefon',
    'email' => 'E-posta',
    'website' => 'Web sitesi',
    'footer' => 'Teklif alt notu',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify();
    $params = [];
    foreach ($fields as $key => $label) {
        if (!is_string($_POST[$key] ?? null)) {
            http_response_code(400);
            exit('Alt bilgi alanlarını kontrol edin.');
        }
        $params[] = $key;
        $params[] = trim($_POST[$key]);
    }
    $values = implode(',', array_fill(0, count($fields), '(?, ?)'));
    $stmt = $pdo->prepare("INSERT INTO company_settings (setting_key, setting_value) VALUES $values
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute($params);
    redirect('settings.php?saved=1');
}

$data = $pdo->query('SELECT setting_key, setting_value FROM company_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
require __DIR__.'/inc/layout.php';
top('Teklif Belgesi Alt Bilgisi');
?>
<style>
.quote-footer-settings{max-width:960px}
.quote-footer-settings .footer-columns{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,290px),1fr));gap:20px;align-items:start}
.quote-footer-settings .footer-preview{border-top:2px solid #173b68;padding-top:12px;color:#173b68;overflow-wrap:anywhere}
.quote-footer-settings .footer-help{color:#475569;font-size:.9rem}
.quote-footer-settings input,.quote-footer-settings textarea{box-sizing:border-box;width:100%}
</style>
<section class="quote-footer-settings">
    <div class="heading">
        <h1>Teklif Belgesi Alt Bilgisi</h1>
        <a href="templates.php">← Teklif Şablonları</a>
    </div>
    <div class="footer-columns">
        <form class="panel" method="post">
            <input type="hidden" name="csrf" value="<?=h(csrf())?>">
            <?php if (isset($_GET['saved'])): ?>
                <p class="tag" role="status">Alt bilgi kaydedildi.</p>
            <?php endif; ?>
            <?php foreach ($fields as $key => $label): ?>
                <label><?=h($label)?>
                    <?php if ($key === 'footer'): ?>
                        <textarea name="footer" rows="3"><?=h($data[$key] ?? '')?></textarea>
                    <?php else: ?>
                        <input name="<?=h($key)?>" type="text" inputmode="<?=h(['phone'=>'tel','email'=>'email','website'=>'url'][$key])?>" value="<?=h($data[$key] ?? '')?>">
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
            <p class="footer-help">Değişiklikler daha önce paylaşılmış teklif linklerinde de görünür.</p>
            <button type="submit">Kaydet</button>
        </form>
        <aside class="panel">
            <h2>Alt bilgi önizlemesi</h2>
            <p class="footer-help">Teklif belgesinde görünen kayıtlı bilgiler.</p>
            <div class="footer-preview">
                <strong>EKŞİOĞLU LOJİSTİK</strong><br>
                <span>INTERNATIONAL MOVERS</span>
                <?php foreach ($fields as $key => $label): ?>
                    <?php if (!empty($data[$key])): ?><br><span><?=h($data[$key])?></span><?php endif; ?>
                <?php endforeach; ?>
            </div>
        </aside>
    </div>
</section>
<?php bottom(); ?>
