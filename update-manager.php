<?php
require __DIR__.'/inc/core.php'; auth();
if (!ekAdmin()) {http_response_code(403);exit('Bu alan yalnızca yönetici içindir.');}
require_once __DIR__.'/inc/update-signature.php';
$base=__DIR__; $error='';$success='';
// Installer is admin-only; enforce a session check on every POST.
if ($_SERVER['REQUEST_METHOD']==='POST' && (empty($_SESSION['uid']) || !is_numeric($_SESSION['uid']))) {http_response_code(403);exit('Yetkisiz istek.');}

function up_fail($message){throw new RuntimeException($message);}
function up_valid_path($path){return is_string($path)&&strlen($path)<200&&preg_match('~^(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_.-]+$~D',$path)&&!str_contains($path,'..')&&!str_starts_with($path,'.')&&!in_array(strtolower(basename($path)),['config.php','.htaccess','install.php','update-manager.php'],true)&&preg_match('~\.(php|css|js|png|jpg|jpeg|webp|svg|ico|woff2|txt)$~i',$path);}
function up_sql_backup($pdo,$destination){
 $f=fopen($destination,'wb');if(!$f)up_fail('Veritabanı yedeği oluşturulamadı.');
 fwrite($f,"-- EKŞİOĞLU backup; UTF-8\nSET FOREIGN_KEY_CHECKS=0;\n");
 foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $t){$name=$t[0];$q='`'.str_replace('`','``',$name).'`';$create=$pdo->query('SHOW CREATE TABLE '.$q)->fetch(PDO::FETCH_NUM);fwrite($f,"\nDROP TABLE IF EXISTS $q;\n".$create[1].";\n");$rows=$pdo->query('SELECT * FROM '.$q);while($r=$rows->fetch(PDO::FETCH_ASSOC)){$cols=array_map(fn($c)=>'`'.str_replace('`','``',$c).'`',array_keys($r));$vals=array_map(fn($v)=>$v===null?'NULL':$pdo->quote((string)$v),array_values($r));fwrite($f,'INSERT INTO '.$q.' ('.implode(',',$cols).') VALUES ('.implode(',',$vals).");\n");}}
 fwrite($f,"SET FOREIGN_KEY_CHECKS=1;\n");fclose($f);
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  verify();if(!class_exists('ZipArchive'))up_fail('Sunucuda PHP ZipArchive eklentisi etkin değil.');
  if(empty($_FILES['package'])||$_FILES['package']['error']!==UPLOAD_ERR_OK)up_fail('ZIP dosyası yüklenemedi.');
  if($_FILES['package']['size']>20*1024*1024)up_fail('Paket en fazla 20 MB olabilir.');
  $zip=new ZipArchive();if($zip->open($_FILES['package']['tmp_name'])!==true)up_fail('ZIP açılamadı.');
  if ($zip->numFiles > 110) up_fail('ZIP çok fazla dosya içeriyor.');
  $totalSize=0;
  for($i=0;$i<$zip->numFiles;$i++){
    $st=$zip->statIndex($i);$name=$st['name']??'';
    if(!is_string($name)||str_contains($name,'\\')||str_contains($name,'..')||str_starts_with($name,'/')||str_contains($name,"\0"))up_fail('ZIP dosya yolu güvenli değil.');
    $totalSize+=(int)($st['size']??0);
    if($totalSize>35*1024*1024)up_fail('Açılmış ZIP boyutu 35 MB sınırını aşıyor.');
    // Reject Unix symlinks inside archives.
    if(isset($st['external_attributes']) && ((($st['external_attributes'] >> 16) & 0170000) === 0120000))up_fail('ZIP içinde sembolik bağlantı var.');
  }
  $raw=$zip->getFromName('manifest.json',65537);
  $signature=$zip->getFromName('manifest.sig',2049);
  $publicKey=ekUpdatePublicKey();
  if(!is_string($raw)||!is_string($signature)||!is_string($publicKey)||!ekVerifyUpdateManifest($raw,$signature,$publicKey)){
   up_fail('Güncelleme imzası doğrulanamadı. Paket kurulmadı.');
  }
  $manifest=json_decode($raw,true);
  if(!is_array($manifest)||($manifest['product']??'')!=='eksioglu-panel'||empty($manifest['version'])||!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/',$manifest['version'])||!isset($manifest['files'])||!is_array($manifest['files']))up_fail('Geçersiz EKŞİOĞLU güncelleme paketi.');
  $installedVersionFile=$base.'/.eksioglu-installed-version';
  $installedVersion=is_file($installedVersionFile)?trim((string)file_get_contents($installedVersionFile)):'0.0.0';
  if(!preg_match('/^\d+\.\d+\.\d+$/',$installedVersion))up_fail('Kurulu sürüm bilgisi bozuk.');
  if(version_compare($manifest['version'],$installedVersion,'<='))up_fail('Paket sürümü kurulu sürümden yeni olmalı.');
  if(($manifest['requires_version']??null)!==$installedVersion)up_fail('Bu paket farklı bir panel sürümü gerektiriyor.');
  $files=$manifest['files'];if(!$files||count($files)>100)up_fail('Dosya sayısı sınırı aşıldı.');
  $existing=[];foreach($files as $path=>$hash){if(!up_valid_path($path)||!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/',$hash))up_fail('Güvenli olmayan dosya yolu veya hash.');$data=$zip->getFromName('files/'.$path);if($data===false||hash('sha256',$data)!==$hash)up_fail('Dosya doğrulaması başarısız: '.$path);$parts=explode('/',$path);$check=$base;
    foreach($parts as $part){$check.='/'.$part;if(is_link($check))up_fail('Sembolik bağlantı hedefi reddedildi: '.$path);}
    $existing[$path]=$data;}
  if(isset($manifest['migration']))up_fail('SQL değişikliği içeren paketler desteklenmiyor.');
  $backupDir=dirname($base).'/.eksioglu-update-backups';if(!is_dir($backupDir)&&!mkdir($backupDir,0700,true))up_fail('Yedek dizini oluşturulamadı.');
  file_put_contents($backupDir.'/.htaccess',"Require all denied\nDeny from all\n");file_put_contents($backupDir.'/index.html','');
  $backupDir=realpath($backupDir);if(!$backupDir||str_starts_with($backupDir,$base.DIRECTORY_SEPARATOR))up_fail('Yedek dizini güvenli değil.');
  $id=date('Ymd-His').'-'.bin2hex(random_bytes(4));$archive=$backupDir.'/backup-'.$id.'.zip';$bz=new ZipArchive();if($bz->open($archive,ZipArchive::CREATE|ZipArchive::EXCL)!==true)up_fail('Yedek arşivi oluşturulamadı.');
  foreach($files as $path=>$hash){$target=$base.'/'.$path;if(is_file($target))$bz->addFile($target,'files/'.$path);}
  $dbFile=$backupDir.'/db-'.$id.'.sql';up_sql_backup(db(),$dbFile);$bz->addFile($dbFile,'database.sql');$bz->addFromString('backup-info.txt','Sürüm: '.$manifest['version']."\nTarih: ".date('c'));if(!$bz->close())up_fail('Yedek arşivi tamamlanamadı.');unlink($dbFile);
  $testBackup=new ZipArchive();if($testBackup->open($archive)!==true)up_fail('Yedek doğrulanamadı.');
  if($testBackup->locateName('database.sql')===false){$testBackup->close();up_fail('Veritabanı yedeği eksik.');}$testBackup->close();
  $written=[];
  try{
   foreach($existing as $path=>$data){$target=$base.'/'.$path;$dir=dirname($target);if(!is_dir($dir)&&!mkdir($dir,0755,true))up_fail('Dizin oluşturulamadı: '.$path);$temp=$target.'.updating-'.bin2hex(random_bytes(4));if(file_put_contents($temp,$data,LOCK_EX)!==strlen($data))up_fail('Dosya yazılamadı: '.$path);if(!rename($temp,$target))up_fail('Dosya değiştirilemedi: '.$path);$written[]=$path;}
   $versionTemp=$installedVersionFile.'.updating-'.bin2hex(random_bytes(4));
   if(file_put_contents($versionTemp,$manifest['version'],LOCK_EX)!==strlen($manifest['version'])||!rename($versionTemp,$installedVersionFile)){
    if(is_file($versionTemp))unlink($versionTemp);
    up_fail('Sürüm bilgisi kaydedilemedi.');
   }
  }catch(Throwable $e){$restore=new ZipArchive();if($restore->open($archive)===true){foreach($written as $path){$old=$restore->getFromName('files/'.$path);if($old!==false)file_put_contents($base.'/'.$path,$old,LOCK_EX);else @unlink($base.'/'.$path);}$restore->close();}throw new RuntimeException('Kurulum durduruldu; dosyalar geri alındı. Veritabanı değişmiş olabilir, yedek: '.basename($archive).' Hata: '.$e->getMessage());}
  $success='Sürüm '.htmlspecialchars($manifest['version'],ENT_QUOTES,'UTF-8').' yüklendi. Yedek: '.basename($archive);
  $zip->close();
 }catch(Throwable $e){$error=$e->getMessage();}
}
require __DIR__.'/inc/layout.php';top('Sistem Güncellemeleri');
?>
<div class="card"><h2>Güncelleme Paketi Yükle</h2><p>Yalnızca EKŞİOĞLU için hazırlanmış, kaynağından emin olduğunuz ZIP paketlerini yükleyin. PHP dosyası içeren paketler sunucuda kod çalıştırabilir.</p>
<?php if($error):?><p style="color:#b91c1c"><strong>Hata:</strong> <?=h($error)?></p><?php endif;?>
<?php if($success):?><p style="color:#15803d"><strong><?= $success ?></strong></p><?php endif;?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><label>Güncelleme ZIP dosyası</label><input type="file" name="package" accept=".zip,application/zip" required><p><button type="submit" onclick="return confirm('Güncelleme öncesi yedek alınacak. Devam edilsin mi?')">Güncellemeyi Yükle ve Kur</button></p></form>
<p><small>Güvenlik: SQL migration içeren paketler bu sürümde engellenir. Paket imzası ve dosya özetleri kurulumdan önce doğrulanır.</small></p><p><small>Kurulum önce dosya ve SQL yedeği alır. Dosya hatalarında geri dönüş yapılır. Veritabanı hatalarında yedekten manuel geri yükleme gerekebilir. Yedekler site kökünün üstündeki gizli dizinde tutulur.</small></p></div>
<?php bottom(); ?>
