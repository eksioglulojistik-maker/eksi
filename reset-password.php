<?php
require __DIR__.'/inc/reset-password.php';
$token=(string)($_POST['token']??$_GET['token']??'');$valid=false;$error='';$done=false;$row=null;
if(preg_match('/^[a-f0-9]{64}$/D',$token)&&ekResetReady()){
 $s=db()->prepare('SELECT id,user_id FROM ek_password_resets WHERE token_hash=? AND expires_at>NOW() LIMIT 1');$s->execute([hash('sha256',$token)]);$row=$s->fetch();$valid=(bool)$row;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify();
 try{
  if(!$valid)throw new RuntimeException('Bağlantının süresi dolmuş veya kullanılmış.');
  $pass=(string)($_POST['password']??'');$again=(string)($_POST['confirm']??'');
  if(strlen($pass)<12||strlen($pass)>128)throw new RuntimeException('Şifre 12-128 karakter olmalıdır.');
  if(!hash_equals($pass,$again))throw new RuntimeException('Şifreler eşleşmiyor.');
  $pdo=db();$pdo->beginTransaction();
  try{
   $s=$pdo->prepare('SELECT user_id FROM ek_password_resets WHERE id=? AND token_hash=? AND expires_at>NOW() FOR UPDATE');$s->execute([$row['id'],hash('sha256',$token)]);$locked=$s->fetch();if(!$locked)throw new RuntimeException('Bağlantı kullanılmış.');
   $s=$pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');$s->execute([password_hash($pass,PASSWORD_DEFAULT),$locked['user_id']]);
   $s=$pdo->prepare('DELETE FROM ek_password_resets WHERE user_id=?');$s->execute([$locked['user_id']]);$pdo->commit();$done=true;$valid=false;
  }catch(Throwable $e){$pdo->rollBack();throw $e;}
 }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>Yeni Şifre | EKŞİOĞLU</title><style>body{margin:0;min-height:100vh;background:#eef3fa;font:15px system-ui,Arial;display:grid;place-items:center;color:#132d4d}.card{background:white;border:1px solid #dbe4ef;border-radius:20px;padding:38px;width:min(430px,calc(100vw - 54px));box-shadow:0 20px 70px #10284614}h1{font-size:27px}p{color:#657891;line-height:1.6}label{display:block;font-weight:700;font-size:13px;margin-top:16px}input{width:100%;box-sizing:border-box;padding:14px;border:1px solid #cbd8e8;border-radius:10px;margin-top:8px}button{width:100%;padding:15px;border:0;border-radius:10px;background:#205ba6;color:white;font-weight:700;margin-top:22px}a{display:inline-block;margin-top:22px;color:#205ba6;text-decoration:none}</style></head><body><main class="card"><h1>Yeni Şifre Oluştur</h1><?php if($done):?><p>Şifreniz güncellendi. Yeni şifrenizle giriş yapabilirsiniz.</p><?php elseif($error):?><p role="alert"><?=h($error)?></p><?php endif;?><?php if($valid&&!$done):?><p>En az 12 karakterlik yeni şifrenizi girin.</p><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="token" value="<?=h($token)?>"><label>Yeni Şifre<input type="password" name="password" minlength="12" maxlength="128" autocomplete="new-password" required></label><label>Yeni Şifre Tekrar<input type="password" name="confirm" minlength="12" maxlength="128" autocomplete="new-password" required></label><button>Şifremi Güncelle</button></form><?php elseif(!$done):?><p>Bu bağlantı geçersiz veya süresi dolmuş.</p><a href="forgot-password.php">Yeni bağlantı iste</a><?php endif;?><p><a href="login.php">Giriş ekranına dön</a></p></main></body></html>