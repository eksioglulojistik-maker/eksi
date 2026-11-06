<?php
require __DIR__.'/inc/core.php';
auth();
if (!ekAdmin()) { http_response_code(403); exit('Bu sayfaya yalnızca yönetici erişebilir.'); }
require_once __DIR__.'/inc/ekspertiz-ai-key.php';
$path=ekSurveyKeyPath();
$err='';$notice='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify();
    $action=(string)($_POST['action']??'');
    try {
        if (!$path) throw new RuntimeException('Güvenli anahtar klasörü belirlenemedi. Hosting desteğine başvurun.');
        if ($action==='save') {
            $key=trim((string)($_POST['api_key']??''));
            if (!preg_match('/^sk-[A-Za-z0-9_-]{16,240}$/D',$key)) throw new RuntimeException('Geçerli bir OpenAI API anahtarı girin.');
            $dir=dirname($path);
            if (!is_dir($dir) && !mkdir($dir,0700,true)) throw new RuntimeException('Özel klasör oluşturulamadı.');
            @chmod($dir,0700);
            if (is_link($path)) throw new RuntimeException('Güvensiz dosya bağlantısı.');
            $temp=tempnam($dir,'key-');
            if ($temp===false) throw new RuntimeException('Geçici dosya oluşturulamadı.');
            try {
                @chmod($temp,0600);
                $content="<?php\nreturn ".var_export($key,true).";\n";
                if (file_put_contents($temp,$content,LOCK_EX)===false) throw new RuntimeException('Anahtar kaydedilemedi.');
                if (!rename($temp,$path)) throw new RuntimeException('Anahtar dosyası yerleştirilemedi.');
                @chmod($path,0600);
            } finally { if(is_file($temp)) @unlink($temp); }
            $notice='API anahtarı güvenli şekilde kaydedildi.';
        } elseif ($action==='delete') {
            if (is_file($path) && !is_link($path) && !unlink($path)) throw new RuntimeException('Anahtar silinemedi.');
            $notice='Kaydedilmiş API anahtarı kaldırıldı.';
        } else throw new RuntimeException('Geçersiz işlem.');
    } catch(Throwable $e) { $err=$e->getMessage(); }
}
require __DIR__.'/inc/layout.php';top('Ekspertiz Yapay Zekâ Ayarları');
$ready=ekSurveyApiKey()!=='';
?>
<style>
.ai-config{max-width:740px;margin:26px auto;padding:0 14px;font-family:inherit}
.ai-config .panel{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:28px;box-shadow:0 8px 30px #10294d0b}
.ai-config h1{font-size:26px;color:#10294d;margin:0 0 10px}.ai-config p{line-height:1.65;color:#475569}
.ai-config label{display:block;font-weight:650;color:#243b59;margin:20px 0 8px}
.ai-config input{width:100%;box-sizing:border-box;padding:13px;border:1px solid #cbd5e1;border-radius:10px;font-size:15px}
.ai-config button,.ai-config .link{display:inline-block;background:#123e76;color:white;border:0;border-radius:10px;padding:12px 19px;cursor:pointer;text-decoration:none;margin-top:16px}
.ai-config .status{border-radius:10px;background:#eef5ff;padding:12px 14px;color:#123e76;margin:18px 0}
.ai-config .error{background:#fff0f0;color:#a11}.ai-config .success{background:#edf9f0;color:#166534}
.ai-config .muted{font-size:13px}.ai-config .danger{background:#fff;color:#a11;border:1px solid #fca5a5}
</style>
<div class="ai-config"><div class="panel">
<h1>Yapay Zekâ Bağlantısı</h1>
<p>Ekspertiz fotoğraflarından eşya tanıma hizmetini etkinleştirin. API anahtarınız panel veritabanına veya web sitesinin erişilebilir klasörüne kaydedilmez.</p>
<div class="status"><?= $ready?'● Bağlantı anahtarı tanımlı':'○ Henüz API anahtarı tanımlanmadı' ?></div>
<?php if($notice):?><div class="status success"><?=h($notice)?></div><?php endif;?>
<?php if($err):?><div class="status error"><?=h($err)?></div><?php endif;?>
<?php if(!$path):?><div class="status error">Bu hosting hesabında web dışı güvenli ve yazılabilir klasör bulunamadı. Anahtar kaydetme kapalıdır.</div><?php endif;?>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?=h(csrf())?>">
<input type="hidden" name="action" value="save">
<label for="api_key">OpenAI API anahtarı</label>
<input id="api_key" type="password" name="api_key" placeholder="sk-..." required minlength="19" autocomplete="new-password" <?=!$path?'disabled':''?>>
<p class="muted">Anahtarı yalnızca buraya yapıştırın. Sohbete veya ekran görüntüsüne eklemeyin. Kaydedildikten sonra tekrar görüntülenmez.</p>
<button type="submit" <?=!$path?'disabled':''?>>Anahtarı Güvenli Kaydet</button>
<a class="link" href="ekspertiz.php">Ekspertizlere Dön</a>
</form>
<?php if($path && is_file($path)):?><form method="post" onsubmit="return confirm('Kayıtlı API anahtarı kaldırılsın mı?')">
<input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="delete">
<button class="danger" type="submit">Kayıtlı Anahtarı Kaldır</button></form><?php endif;?>
<p class="muted">OpenAI API kullanımı ayrıca ücretlendirilir. Fotoğraf analizi yalnızca kullanıcı başlattığında gerçekleşir.</p>
</div></div>
<?php bottom(); ?>
