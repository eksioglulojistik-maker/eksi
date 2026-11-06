<?php
require __DIR__.'/inc/core.php';auth();require_once __DIR__.'/inc/record-protection.php';
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405);header('Allow: POST');exit('Bu işlem POST gerektirir.'); }
verify();
$id=filter_var($_POST['quote_id']??null,FILTER_VALIDATE_INT);
$nonce=$_POST['revision_nonce']??null;$version=$_POST['record_version']??null;
if (!$id || $id<1 || !is_string($nonce) || !preg_match('/^[a-f0-9]{64}$/D',$nonce) || !is_string($version)) { http_response_code(400);exit('Geçersiz revizyon isteği.'); }
if (isset($_SESSION['quote_revision_results'][$nonce]) && $_SESSION['quote_revision_results'][$nonce]['source']===$id) redirect('quote.php?id='.(int)$_SESSION['quote_revision_results'][$nonce]['new']);
if (!is_string($_SESSION['quote_revision_nonce'][$id]??null) || !hash_equals($_SESSION['quote_revision_nonce'][$id],$nonce)) { http_response_code(409);exit('Revizyon isteği eski veya geçersiz. Teklifi yeniden açın.'); }
try {
    $newId=ekCreateDraftRevision(db(),$id,$version,(int)$_SESSION['uid']);
    $_SESSION['quote_revision_results'][$nonce]=['source'=>$id,'new'=>$newId];
    unset($_SESSION['quote_revision_nonce'][$id]);
    if(count($_SESSION['quote_revision_results'])>50)array_shift($_SESSION['quote_revision_results']);
    redirect('quote.php?id='.$newId);
} catch(Throwable $e) {
    error_log('Draft revision failed: '.$e->getMessage());http_response_code(409);
    require __DIR__.'/inc/layout.php';top('Revizyon oluşturulamadı');
    echo '<section class="card"><h1>Revizyon oluşturulamadı</h1><p>'.h($e instanceof PDOException?'İşlem tamamlanamadı. Kaynak teklif korundu.':$e->getMessage()).'</p><a href="quote.php?id='.(int)$id.'">Teklife dön</a></section>';bottom();
}
