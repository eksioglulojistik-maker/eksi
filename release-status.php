<?php
require __DIR__.'/inc/core.php';auth();require_once __DIR__.'/inc/permissions.php';ek_require_admin();
require_once __DIR__.'/inc/release-verifier.php';header('Cache-Control: no-store');
$release=[];try{$value=require __DIR__.'/inc/release-manifest.php';if(is_array($value))$release=$value;}catch(Throwable $e){error_log('Release manifest could not be read.');}
$versionFile=__DIR__.'/.eksioglu-installed-version';$installed=is_readable($versionFile)?trim((string)file_get_contents($versionFile)):null;
$check=ekVerifyRelease(__DIR__,$release,$installed);
require __DIR__.'/inc/layout.php';top('Sürüm Doğrulama');
?>
<section class="card"><h1>Sürüm Doğrulama</h1>
<p>Kurulu sürüm kaydı: <strong><?=h($installed??'Bilinmiyor')?></strong> · Doğrulama manifesti: <strong><?=h(is_string($release['version']??null)?$release['version']:'Geçersiz')?></strong> · Build: <?=h(is_string($release['build']??null)?$release['build']:'—')?></p>
<p role="status"><?= $check['ok']?'Manifest kapsamındaki dosyalar ve sürüm kaydı eşleşiyor.':'Sürüm kaydı veya manifest kapsamındaki dosyalarda uyuşmazlık var. Kurulum doğrulanmadı.' ?></p>
<?php if(!$check['valid']):?><p role="alert">Doğrulama manifesti eksik veya geçersiz. Geçerli dağıtım paketiyle kontrol edilmelidir.</p><?php elseif(!$check['version_ok']):?><p role="alert">Kurulu sürüm kaydı ile manifest sürümü farklı. Sonraki güncelleme paketinin güncel manifesti içerdiğini kontrol edin.</p><?php endif;?>
<p>Bu kontrol yalnız aşağıdaki dosyaları kapsar; veritabanı, müşteri verileri, sunucu yapılandırması ve uygulamanın tamamının çalışması için kabul testi değildir. Dosya özetleri güvenilen dağıtım kaynağından gelir; canlı dosyalar otomatik olarak doğru kabul edilmez.</p>
<div class="table-wrap"><table><thead><tr><th scope="col">Dosya</th><th scope="col">Sonuç</th><th scope="col">Dosya özeti</th></tr></thead><tbody>
<?php foreach($check['rows'] as $r):?><tr><td><?=h($r['path'])?></td><td><?=$r['ok']?'Eşleşiyor':($r['actual']===null?'Eksik / okunamıyor':'Eşleşmiyor')?></td><td><details><summary>SHA-256 ayrıntıları</summary><p style="overflow-wrap:anywhere">Beklenen: <?=h($r['expected'])?><br>Sunucuda: <?=h($r['actual']??'Okunamadı')?></p></details></td></tr><?php endforeach;?></tbody></table></div>
</section><?php bottom(); ?>
