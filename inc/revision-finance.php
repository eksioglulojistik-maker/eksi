<?php
require_once __DIR__.'/record-protection.php';
// Integer minor units throughout: never add currencies or round binary floats.
function ekRevisionCents(string $amount): int {
    if(!preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,2}))?$/D',$amount,$m))throw new RuntimeException('Teklif bedeli geçersiz.');
    return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
}
function ekRevisionFinance(PDO $pdo, int $id): array {
    if(!$pdo->inTransaction())throw new LogicException('Finans incelemesi transaction gerektirir.');
    $chain=[];$seen=[];$next=$id;
    while($next>0){
        if(isset($seen[$next])||count($chain)>=64)throw new RuntimeException('Revizyon zinciri döngülü veya çok uzun.');
        $seen[$next]=true;$s=$pdo->prepare('SELECT * FROM quotes WHERE id=?');$s->execute([$next]);$q=$s->fetch(PDO::FETCH_ASSOC);
        if(!$q)throw new RuntimeException('Revizyon zincirinde eksik teklif var.');
        $state=ekQuoteState($pdo,$q);$chain[]=$state;
        $parent=$state['moving']['revision_parent_id']??0;
        if(!is_int($parent)&&!(is_string($parent)&&ctype_digit($parent)))throw new RuntimeException('Kaynak teklif ilişkisi geçersiz.');
        if((int)$parent<0)throw new RuntimeException('Kaynak teklif ilişkisi geçersiz.');
        $next=(int)$parent;
    }
    if(count($chain)<2)throw new RuntimeException('Bu kayıt geçerli bir taslak revizyon değil.');
    $currency=$chain[0]['quote']['currency'];$customer=(int)$chain[0]['quote']['customer_id'];$blocks=[];$evidence=[];
    $ready=(bool)$pdo->query("SHOW TABLES LIKE 'ek_quote_payments'")->fetchColumn();
    $sigReady=ekTableExists($pdo,'ek_contract_signatures');
    $contractReady=(bool)$pdo->query("SHOW TABLES LIKE 'v11_contracts'")->fetchColumn();
    $archiveReady=ekTableExists($pdo,'quote_archives');
    if(!$ready)$blocks[]='Tahsilat tablosu bulunamadı; bakiye doğrulanamadı.';
    if(!$sigReady||!$contractReady)$blocks[]='Sözleşme veya imza tablosu eksik; belge durumu doğrulanamadı.';
    if(!in_array($currency,['EUR','USD','TRY','GBP'],true))$blocks[]='Para birimi desteklenmiyor.';
    if($chain[0]['quote']['status']!=='draft')$blocks[]='İncelenen revizyon taslak durumunda değil.';
    $payments=[];$priorPaid=0;$childPaid=0;$signed=false;
    foreach($chain as $i=>$state){
        $q=$state['quote'];$qid=(int)$q['id'];
        if((int)$q['customer_id']!==$customer)$blocks[]='Zincirde farklı müşterilere ait teklifler var.';
        if($q['currency']!==$currency)$blocks[]='Teklif zincirinde farklı para birimleri var; otomatik mahsup yapılamaz.';
        $rows=[];
        if($ready){$s=$pdo->prepare('SELECT id,quote_id,amount_cents,currency,paid_at,method,note,created_by FROM ek_quote_payments WHERE quote_id=? ORDER BY id');$s->execute([$qid]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);}
        if($q['status']==='draft'&&$i<count($chain)-1&&$rows)$blocks[]='Revizyon taslağında eski tahsilat kaydı var; otomatik inceleme tamamlanamaz.';
        foreach($rows as $row){
            $raw=(string)$row['amount_cents'];
            if(!preg_match('/^[0-9]{1,14}$/D',$raw)||$raw==='0'){$blocks[]='Geçersiz veya negatif tahsilat kaydı var; manuel inceleme gerekir.';continue;}
            $cents=(int)$raw;$key=(string)$row['currency'];$payments[$key]=($payments[$key]??0)+$cents;
            if($payments[$key]>900000000000000)throw new RuntimeException('Tahsilat toplamı güvenli hesaplama sınırını aşıyor.');
            if($key!==$currency)$blocks[]='Farklı para biriminde tahsilat var; kur dönüşümü yapılmadı.';
            elseif($i===0)$childPaid+=$cents;else $priorPaid+=$cents;
        }
        if($archiveReady){$s=$pdo->prepare('SELECT quote_id FROM quote_archives WHERE quote_id=?');$s->execute([$qid]);if($s->fetchColumn())$blocks[]='Zincirde arşivlenmiş teklif var.';}
        $signature=null;$contract=null;
        if($sigReady){$s=$pdo->prepare('SELECT contract_hash,signed_at FROM ek_contract_signatures WHERE quote_id=?');$s->execute([$qid]);$signature=$s->fetch(PDO::FETCH_ASSOC)?:null;}
        if($contractReady){$s=$pdo->prepare('SELECT language,body,created_at FROM v11_contracts WHERE quote_id=?');$s->execute([$qid]);$contract=$s->fetch(PDO::FETCH_ASSOC)?:null;}
        if($signature)$signed=true;
        // Evidence contains no signature image; hash the canonical document body.
        $fingerprintState=$state;foreach(['revision_offer','revision_activated','revision_effective_child_id','revision_pending_id'] as $systemKey)unset($fingerprintState['moving'][$systemKey]);
        $evidence[]=['state'=>$fingerprintState,'payments'=>$rows,'signature'=>$signature,'contract'=>$contract];
    }
    if($childPaid>0)$blocks[]='Revizyon taslağında eski tahsilat kaydı var; otomatik inceleme tamamlanamaz.';
    $root=$chain[count($chain)-1]['quote'];
    if(!in_array($root['status'],['accepted','approved','completed'],true))$blocks[]='Zincirin başlangıcında onaylı kaynak teklif yok.';
    $candidate=ekRevisionCents((string)$chain[0]['quote']['total']);$source=ekRevisionCents((string)$chain[1]['quote']['total']);
    ksort($payments);$blocks=array_values(array_unique($blocks));
    return ['currency'=>$currency,'candidate_cents'=>$candidate,'source_cents'=>$source,'difference_cents'=>$chain[1]['quote']['currency']===$currency?$candidate-$source:null,
        'prior_paid_cents'=>$priorPaid,'remaining_cents'=>$blocks?null:$candidate-$priorPaid,
        'additional_cents'=>$blocks?null:max(0,$candidate-$priorPaid),'refund_cents'=>$blocks?null:max(0,$priorPaid-$candidate),
        'payments_by_currency'=>$payments,'blocks'=>$blocks,'has_signature'=>$signed,
        'chain_ids'=>array_map(fn($state)=>(int)$state['quote']['id'],$chain),
        'fingerprint'=>hash('sha256',json_encode($evidence,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))];
}
function ekSaveRevisionFinanceReview(PDO $pdo,int $id,string $expected,int $actor,string $note): void {
    if($pdo->inTransaction()||$actor<1||!preg_match('/^[a-f0-9]{64}$/D',$expected)||mb_strlen(trim($note))<5||mb_strlen($note)>500)throw new RuntimeException('İnceleme isteği veya açıklaması geçersiz.');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
    try{
        // Discover, then lock all quote rows in ascending order. Recheck the chain
        // and financial inputs under the locks before appending the audit event.
        $before=ekRevisionFinance($pdo,$id);$ids=$before['chain_ids'];sort($ids,SORT_NUMERIC);
        foreach($ids as $qid)ekLockQuote($pdo,$qid);
        // All writers in this workflow lock the quote first.
        foreach($ids as $qid){
            foreach(['moving_quote_meta','quote_details','ek_quote_payments','v11_contracts','ek_contract_signatures'] as $table){
                $s=$pdo->prepare('SELECT * FROM '.$table.' WHERE quote_id=? FOR UPDATE');$s->execute([$qid]);$s->fetchAll();
            }
        }
        $current=ekRevisionFinance($pdo,$id);
        if($current['chain_ids']!==$before['chain_ids']||!hash_equals($expected,$current['fingerprint']))throw new RuntimeException('Teklif, sözleşme veya tahsilat değişmiş. İncelemeyi yenileyin.');
        if($current['blocks'])throw new RuntimeException(implode(' ',$current['blocks']));
        $s=$pdo->prepare("SELECT payload FROM v11_quote_events WHERE quote_id=? AND event_type='revision_finance_reviewed' ORDER BY id DESC LIMIT 1");$s->execute([$id]);$last=json_decode($s->fetchColumn()?:'{}',true);
        if(($last['fingerprint']??'')!==$current['fingerprint']){
            $s=$pdo->prepare('INSERT INTO v11_quote_events(quote_id,event_type,actor_id,payload) VALUES(?,?,?,?)');
            $s->execute([$id,'revision_finance_reviewed',$actor,json_encode(['fingerprint'=>$current['fingerprint'],'summary'=>$current,'note'=>trim($note),'effective_quote_changed'=>false],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
