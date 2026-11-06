<?php
require_once __DIR__.'/record-protection.php';
require_once __DIR__.'/revision-finance.php';
function ekRevisionPaymentReason(PDO $pdo, int $id): string {
    $s=$pdo->prepare('SELECT payload FROM moving_quote_meta WHERE quote_id=?');$s->execute([$id]);
    $meta=json_decode($s->fetchColumn()?:'{}',true,512,JSON_THROW_ON_ERROR);
    if(!is_array($meta))throw new RuntimeException('Revizyon bilgileri okunamadı.');
    if(!empty($meta['revision_effective_child_id']))return 'Tahsilat geçerli revizyon üzerinden kaydedilmelidir.';
    return (int)($meta['revision_parent_id']??0)>0&&empty($meta['revision_activated'])
        ? 'Revizyon taslağına tahsilat kaydedilemez. Önce kaynak sözleşme ve tahsilatlarla mutabakat gerekir.' : '';
}
function ekRevisionReview(PDO $pdo, int $id): array {
    if($pdo->inTransaction())throw new LogicException('İnceleme ayrı transaction gerektirir.');
    // One consistent read view; no business records or schema are changed.
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare('SELECT * FROM quotes WHERE id=?');$s->execute([$id]);$child=$s->fetch(PDO::FETCH_ASSOC);
        if(!$child)throw new RuntimeException('Revizyon bulunamadı.');
        $state=ekQuoteState($pdo,$child);$parentId=(int)($state['moving']['revision_parent_id']??0);
        if($parentId<1||$parentId===$id)throw new RuntimeException('Bu kayıt geçerli bir taslak revizyon değil.');
        $s->execute([$parentId]);$parent=$s->fetch(PDO::FETCH_ASSOC);
        if(!$parent)throw new RuntimeException('Kaynak teklif bulunamadı.');
        $paymentsReady=(bool)$pdo->query("SHOW TABLES LIKE 'ek_quote_payments'")->fetchColumn();
        $items=[];
        foreach([$parent,$child] as $q){
            $s=$pdo->prepare('SELECT name,company FROM customers WHERE id=?');$s->execute([$q['customer_id']]);$customer=$s->fetch(PDO::FETCH_ASSOC)?:[];
            $payments=[];
            if($paymentsReady){$s=$pdo->prepare('SELECT currency,SUM(amount_cents) AS cents,COUNT(*) AS count FROM ek_quote_payments WHERE quote_id=? GROUP BY currency ORDER BY currency');$s->execute([$q['id']]);$payments=$s->fetchAll(PDO::FETCH_ASSOC);}
            $items[]=['quote'=>$q,'customer'=>$customer,'payments'=>$payments,'signed'=>ekQuoteHasSignature($pdo,(int)$q['id'])];
        }
        $finance=ekRevisionFinance($pdo,$id);
        $s=$pdo->prepare("SELECT payload,created_at,actor_id FROM v11_quote_events WHERE quote_id=? AND event_type='revision_finance_reviewed' ORDER BY id DESC LIMIT 1");$s->execute([$id]);$last=$s->fetch(PDO::FETCH_ASSOC)?:null;
        $reviewed=false;if($last){$record=json_decode($last['payload'],true,512,JSON_THROW_ON_ERROR);$reviewed=is_string($record['fingerprint']??null)&&hash_equals($finance['fingerprint'],$record['fingerprint']);}
        $pdo->commit();return ['items'=>$items,'payments_ready'=>$paymentsReady,'finance'=>$finance,'reviewed'=>$reviewed,'last_review'=>$last,'transition'=>ekRevisionMeta($pdo,$id)];
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
