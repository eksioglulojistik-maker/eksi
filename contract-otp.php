<?php
require_once __DIR__.'/inc/v11.php';
function ek_otp_table(){
 db()->exec("CREATE TABLE IF NOT EXISTS ek_contract_otp (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,token_hash CHAR(64) NOT NULL,quote_id INT NOT NULL,channel VARCHAR(8) NOT NULL,code_hash CHAR(64) NOT NULL,expires_at DATETIME NOT NULL,attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,verified_at DATETIME NULL,created_at DATETIME NOT NULL,UNIQUE KEY uq_token (token_hash),KEY idx_quote (quote_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function ek_otp_destination($qid,$channel){
 if($channel!=='email')throw new RuntimeException('Yalnızca e-posta doğrulaması kullanılabilir.');
 $s=db()->prepare('SELECT c.email FROM quotes q JOIN customers c ON c.id=q.customer_id WHERE q.id=?');
 $s->execute([$qid]);$r=$s->fetch(PDO::FETCH_ASSOC);
 if(!$r)throw new RuntimeException('Müşteri bulunamadı.');
 $address=trim((string)$r['email']);
 if(!filter_var($address,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Müşterinin kayıtlı e-posta adresi geçersiz.');
 return $address;
}
function ek_otp_send($channel,$destination,$code){
 if($channel!=='email')throw new RuntimeException('Yalnızca e-posta doğrulaması kullanılabilir.');
 require_once __DIR__.'/inc/smtp-mail.php';
 sm_send($destination,'EKŞİOĞLU sözleşme doğrulama kodu',"Sözleşmenizi imzalamak için doğrulama kodunuz: ".$code."\nKod 5 dakika geçerlidir. Bu kodu kimseyle paylaşmayın.");
}
