<?php
require __DIR__.'/inc/v11.php';
auth();
$id=filter_var($_GET['id']??$_POST['id']??null,FILTER_VALIDATE_INT);
if(!$id||$id<1){http_response_code(400);exit('Teklif numarası geçersiz.');}
header('Location: quote-actions.php?id='.$id,true,$_SERVER['REQUEST_METHOD']==='POST'?307:302);
exit;
