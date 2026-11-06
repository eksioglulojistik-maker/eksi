<?php
require __DIR__.'/inc/reset-password.php';
$sent=false;$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify();$sent=true;
 try{
  if(!ekResetThrottle())throw new RuntimeException('Lütfen biraz bekleyip tekrar deneyin.');
  if(ekResetReady()){
   $email=mb_strtolower(trim((string)($_POST['email']??'')));
   if(filter_var($email,FILTER_VALIDATE_EMAIL)&&strlen($email)<=254){
    $s=db()->prepare('SELECT id,email FROM users WHERE email=? LIMIT 1');$s->execute([$email]);$u=$s->fetch();
    if($u){
     $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);
     db()->beginTransaction();
     try{$s=db()->prepare('DELETE FROM ek_password_resets WHERE user_id=?');$s->execute([$u['id']]);$s=db()->prepare('INSERT INTO ek_password_resets(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))');$s->execute([$u['id'],$hash]);db()->commit();}catch(Throwable $e){db()->rollBack();throw $e;}
     try{require_once __DIR__.'/inc/smtp-mail.php';sm_send($u['email'],'EKŞİOĞLU - Şifre Sıfırlama',"Şifrenizi sıfırlamak için aşağıdaki bağlantıyı 30 dakika içinde açın:
".ekResetUrl($token)."

Bu isteği siz yapmadıysanız mesajı dikkate almayın.");}
     catch(Throwable $e){error_log('EK reset email delivery failed: '.get_class($e));}
    }
   }
  }
 }catch(Throwable $e){$error='İstek şu anda tamamlanamadı. Bir süre sonra tekrar deneyin.';}
}
?><!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Şifremi Unuttum | EKŞİOĞLU</title><style>body{margin:0;min-height:100vh;background:#eef3fa;font:15px system-ui,Arial;display:grid;place-items:center;color:#132d4d}.card{background:#fff;border:1px solid #dbe4ef;border-radius:20px;box-shadow:0 20px 70px #10284614;padding:38px;width:min(430px,calc(100vw - 54px))}.logo{max-width:220px;display:block;margin-bottom:28px}h1{font-size:27px}p{color:#657891;line-height:1.6}label{font-weight:700;font-size:13px}input{width:100%;box-sizing:border-box;padding:14px;border:1px solid #cbd8e8;border-radius:10px;margin:9px 0 20px}button{width:100%;padding:15px;border:0;border-radius:10px;background:#205ba6;color:#fff;font-weight:700;cursor:pointer}a{display:inline-block;margin-top:24px;color:#205ba6;text-decoration:none}.msg{padding:15px;background:#e9f5ed;border-radius:10px;color:#165f3d}</style></head><body><main class="card"><img class="logo" src="assets/eksioglu-logo.png" alt="EKŞİOĞLU LOJİSTİK"><h1>Şifremi Unuttum</h1><p>Hesabınıza kayıtlı e-posta adresinizi girin. Hesap bulunuyorsa şifre sıfırlama bağlantısı gönderilecektir.</p><?php if($sent&&!$error):?><div class="msg">E-posta adresi kayıtlıysa sıfırlama bağlantısı gönderilecektir. Gelen kutunuzu kontrol edin.</div><?php endif;?><?php if($error):?><p role="alert"><?=h($error)?></p><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><label for="email">E-posta adresi</label><input id="email" name="email" type="email" maxlength="254" autocomplete="email" required><button>Bağlantı Gönder</button></form><a href="login.php">← Giriş ekranına dön</a></main></body></html>