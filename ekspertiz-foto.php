<?php
require __DIR__.'/inc/core.php';auth();
$id=(int)($_GET['id']??0);$q=db()->prepare('SELECT p.file_path,p.mime,s.assigned_user_id FROM ek_survey_photos p JOIN ek_surveys s ON s.id=p.survey_id WHERE p.id=?');$q->execute([$id]);$r=$q->fetch();
if(!$r || (!ekAdmin() && (int)$r['assigned_user_id']!==(int)$_SESSION['uid'])){http_response_code(403);exit('Erişim engellendi.');}
$path=__DIR__.'/survey_uploads/'.basename($r['file_path']);if(!is_file($path)){http_response_code(404);exit('Bulunamadı.');}
header('Content-Type: '.$r['mime']);header('Content-Disposition: inline; filename="ekspertiz-foto"');header('Content-Length: '.filesize($path));readfile($path);
