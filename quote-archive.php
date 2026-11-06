<?php
require __DIR__.'/inc/v11.php';auth();
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('Geçersiz yöntem.');}
verify();
if(!v111_is_admin()){http_response_code(403);exit('Teklif silme ve geri yükleme yalnızca yönetici tarafından yapılabilir.');}
$id=filter_input(INPUT_POST,'quote_id',FILTER_VALIDATE_INT);$action=(string)($_POST['action']??'');
if(!$id||!in_array($action,['archive','restore'],true)){http_response_code(400);exit('Geçersiz işlem.');}
$pdo=db();
$check=$pdo->prepare('SELECT id FROM quotes WHERE id=?');$check->execute([$id]);if(!$check->fetchColumn()){http_response_code(404);exit('Teklif bulunamadı.');}
try{
 $pdo->beginTransaction();ekLockQuote($pdo,$id);$meta=ekRevisionMeta($pdo,$id);if(!empty($meta['revision_activated'])||!empty($meta['revision_effective_child_id'])||!empty($meta['revision_pending_id'])||($meta['revision_offer']['state']??'')==='offered')throw new RuntimeException('Revizyon geçişine bağlı kayıt arşivlenemez.');
 if($action==='archive'){$s=$pdo->prepare('INSERT INTO quote_archives (quote_id,archived_by) VALUES (?,?) ON DUPLICATE KEY UPDATE archived_at=CURRENT_TIMESTAMP,archived_by=VALUES(archived_by)');$s->execute([$id,(int)$_SESSION['uid']]);}
 else{$s=$pdo->prepare('DELETE FROM quote_archives WHERE quote_id=?');$s->execute([$id]);}
$pdo->commit();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();http_response_code(409);exit('İşlem tamamlanamadı. Revizyon geçişine bağlı kayıtlar korunur.');}
redirect($action==='archive'?'quotes.php':'quotes.php?archived=1');
