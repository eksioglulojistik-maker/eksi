<?php
require __DIR__.'/inc/core.php';auth();require __DIR__.'/inc/layout.php';
$pdo=db();$id=(int)$_SESSION['uid'];$st=$pdo->prepare('SELECT id,name,email,password_hash FROM users WHERE id=?');$st->execute([$id]);$user=$st->fetch();if(!$user){session_destroy();redirect('login.php');}
$error='';$success='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify();$action=(string)($_POST['action']??'');
 try{
  if($action==='profile'){
   $name=trim((string)($_POST['name']??''));$email=mb_strtolower(trim((string)($_POST['email']??'')));
   $password=(string)($_POST['current_password']??'');
   if(mb_strlen($name)<2||mb_strlen($name)>120)throw new RuntimeException('Ad soyad 2-120 karakter olmalıdır.');
   if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254)throw new RuntimeException('Geçerli e-posta girin.');
   if(!password_verify($password,$user['password_hash']))throw new RuntimeException('Mevcut şifreniz yanlış.');
   $s=$pdo->prepare('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1');$s->execute([$email,$id]);if($s->fetch())throw new RuntimeException('Bu e-posta başka bir hesapta kullanılıyor.');
   $s=$pdo->prepare('UPDATE users SET name=?,email=? WHERE id=?');$s->execute([$name,$email,$id]);$_SESSION['name']=$name;$user['name']=$name;$user['email']=$email;$success='Hesap bilgileriniz güncellendi.';
  }elseif($action==='password'){
   $current=(string)($_POST['current_password']??'');$new=(string)($_POST['new_password']??'');$confirm=(string)($_POST['confirm_password']??'');
   if(!password_verify($current,$user['password_hash']))throw new RuntimeException('Mevcut şifreniz yanlış.');
   if(strlen($new)<12||strlen($new)>128)throw new RuntimeException('Yeni şifre 12-128 karakter olmalıdır.');
   if(!hash_equals($new,$confirm))throw new RuntimeException('Yeni şifreler eşleşmiyor.');
   if(password_verify($new,$user['password_hash']))throw new RuntimeException('Yeni şifre eskisiyle aynı olamaz.');
   $hash=password_hash($new,PASSWORD_DEFAULT);$s=$pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');$s->execute([$hash,$id]);
   $s=$pdo->prepare('DELETE FROM ek_password_resets WHERE user_id=?');try{$s->execute([$id]);}catch(PDOException $ignored){}
   $user['password_hash']=$hash;$_SESSION['account_proof']=ekSessionProof($hash);session_regenerate_id(true);$success='Şifreniz başarıyla değiştirildi.';
  }else throw new RuntimeException('Geçersiz işlem.');
 }catch(Throwable $e){$error=$e instanceof PDOException?'İşlem tamamlanamadı. Verileri kontrol edin.':$e->getMessage();}
}
top('Hesabım');echo '<style>.ac-wrap{max-width:880px;margin:0 auto}.ac-card{background:#fff;border:1px solid #e0e8f2;border-radius:18px;padding:28px;margin:18px 0;box-shadow:0 8px 24px #10284b08}.ac-card h2{font-size:19px;color:#102b4b;margin:0 0 8px}.ac-muted{color:#475569;font-size:14px;line-height:1.6}.ac-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.ac-field{display:block;font-size:13px;font-weight:700;color:#1c3555;margin:16px 0}.ac-field input{display:block;width:100%;margin-top:8px;padding:13px 14px;border:1px solid #cfdae7;border-radius:10px;font:inherit;box-sizing:border-box}.ac-btn{border:0;border-radius:10px;background:#205ba6;color:#fff;font-weight:700;padding:13px 20px;cursor:pointer}.ac-msg{border-radius:10px;padding:14px 16px;background:#eaf4ee;color:#165d3a;margin:15px 0}.ac-err{background:#fff0f0;color:#a12d2d}.ac-header{display:flex;align-items:center;justify-content:space-between;gap:10px}@media(max-width:700px){.ac-grid{grid-template-columns:1fr}.ac-card{padding:18px}}</style>';?>
<div class="ac-wrap"><div class="heading"><div><h1>Hesabım</h1><p>Hesap bilgilerinizi ve güvenlik ayarlarınızı yönetin.</p></div></div>
<?php if($error):?><div class="ac-msg ac-err" role="alert"><?=h($error)?></div><?php endif;?><?php if($success):?><div class="ac-msg" role="status"><?=h($success)?></div><?php endif;?>
<div class="ac-card"><h2>Profil Bilgileri</h2><p class="ac-muted">E-posta değişikliğini onaylamak için mevcut şifrenizi girin.</p><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="profile"><div class="ac-grid"><label class="ac-field">Ad Soyad<input required maxlength="120" name="name" autocomplete="name" value="<?=h($user['name'])?>"></label><label class="ac-field">Giriş E-postası<input required maxlength="254" type="email" name="email" autocomplete="email" value="<?=h($user['email'])?>"></label></div><label class="ac-field">Mevcut Şifre<input required type="password" name="current_password" autocomplete="current-password"></label><button class="ac-btn">Bilgileri Kaydet</button></form></div>
<div class="ac-card"><h2>Şifre Değiştir</h2><p class="ac-muted">Güçlü, başka yerde kullanılmayan en az 12 karakterlik şifre tercih edin.</p><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="password"><label class="ac-field">Mevcut Şifre<input required type="password" name="current_password" autocomplete="current-password"></label><div class="ac-grid"><label class="ac-field">Yeni Şifre<input required minlength="12" maxlength="128" type="password" name="new_password" autocomplete="new-password"></label><label class="ac-field">Yeni Şifre Tekrar<input required minlength="12" maxlength="128" type="password" name="confirm_password" autocomplete="new-password"></label></div><button class="ac-btn">Şifremi Değiştir</button></form></div></div><?php bottom(); ?>