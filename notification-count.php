<?php
require __DIR__.'/inc/core.php';
auth();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
$count=0;
try {
 $s=db()->prepare('SELECT COUNT(*) FROM ek_notifications WHERE recipient_id=? AND read_at IS NULL');
 $s->execute([(int)($_SESSION['uid']??0)]);
 $count=(int)$s->fetchColumn();
} catch(Throwable $e) {}
echo json_encode(['unread'=>$count], JSON_UNESCAPED_UNICODE);
