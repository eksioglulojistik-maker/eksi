<?php
require_once __DIR__.'/revision-finance.php';
function ekRevisionMeta(PDO $p,int $id):array {
    $s=$p->prepare('SELECT payload FROM moving_quote_meta WHERE quote_id=?');$s->execute([$id]);
    return ekDecodeRevisionMeta($s->fetchColumn());
}
function ekDecodeRevisionMeta($payload):array {
    $m=json_decode($payload?:'{}',true,512,JSON_THROW_ON_ERROR);
    if(!is_array($m))throw new RuntimeException('Revizyon bilgisi okunamadı.');return $m;
}
function ekWriteRevisionMeta(PDO $p,int $id,array $m):void {
    $s=$p->prepare('INSERT INTO moving_quote_meta(quote_id,payload) VALUES(?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload)');$s->execute([$id,json_encode($m,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}
function ekRevisionEvent(PDO $p,int $id,string $event,?int $actor,array $data):void {
    $s=$p->prepare('INSERT INTO v11_quote_events(quote_id,event_type,actor_id,payload) VALUES(?,?,?,?)');$s->execute([$id,$event,$actor,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}
function ekLockRevisionChain(PDO $p,int $id):array {
    $f=ekRevisionFinance($p,$id);$ids=$f['chain_ids'];sort($ids,SORT_NUMERIC);
    foreach($ids as $qid)ekLockQuote($p,$qid);
    $fresh=ekRevisionFinance($p,$id);
    if($f['chain_ids']!==$fresh['chain_ids'])throw new RuntimeException('Revizyon ilişkisi değişmiş.');return $fresh;
}
function ekRevisionOfferToken(int $id,array $offer,string $secret):string {
    return hash_hmac('sha256','ek-revision-v1|'.$id.'|'.($offer['nonce']??''),$secret);
}
function ekRevisionOfferLink(PDO $p,int $id):string {
    $m=ekRevisionMeta($p,$id);$o=$m['revision_offer']??[];
    if(($o['state']??'')!=='offered'||($o['expires_at']??'')<=date('Y-m-d H:i:s'))throw new RuntimeException('Revizyon müşteriye sunulmaya hazır değil.');
    $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';$host=$_SERVER['HTTP_HOST']??'';
    if(!preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]+)?$/D',$host))throw new RuntimeException('Panel adresi doğrulanamadı.');
    return $scheme.'://'.$host.'/customer-revision.php?id='.$id.'&token='.ekRevisionOfferToken($id,$o,DB_PASS);
}
function ekPublishRevision(PDO $p,int $id,string $expected,int $actor):void {
    if($actor<1||$p->inTransaction())throw new RuntimeException('Geçersiz sunum isteği.');
    $p->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$p->beginTransaction();
    try{
        $f=ekLockRevisionChain($p,$id);$m=ekRevisionMeta($p,$id);$root=end($f['chain_ids']);$rm=ekRevisionMeta($p,$root);$parent=(int)$m['revision_parent_id'];$pm=ekRevisionMeta($p,$parent);
        if($f['blocks']||!hash_equals($f['fingerprint'],$expected))throw new RuntimeException('Finans incelemesi güncel ve hatasız olmalıdır.');
        if(!empty($pm['revision_effective_child_id'])||(!empty($rm['revision_pending_id'])&&(int)$rm['revision_pending_id']!==$id))throw new RuntimeException('Kaynak teklifin başka bir geçişi var. Geçerli tekliften devam edin.');
        if(!in_array(ekLockQuote($p,$parent)['status'],['accepted','approved','completed'],true))throw new RuntimeException('Kaynak teklif onaylı değil.');
        if(!empty($m['revision_activated']))throw new RuntimeException('Bu revizyon zaten yürürlükte.');
        if(ekQuoteHasSignature($p,$id))throw new RuntimeException('Revizyon taslağında imza var; yeni bir taslak gerekir.');
        $s=$p->prepare("SELECT payload FROM v11_quote_events WHERE quote_id=? AND event_type='revision_finance_reviewed' ORDER BY id DESC LIMIT 1");$s->execute([$id]);$review=json_decode($s->fetchColumn()?:'{}',true,512,JSON_THROW_ON_ERROR);
        if(!is_string($review['fingerprint']??null)||!hash_equals($f['fingerprint'],$review['fingerprint']))throw new RuntimeException('Önce güncel finans incelemesini kaydedin.');
        if(($m['revision_offer']['state']??'')==='offered'&&$m['revision_offer']['expires_at']>date('Y-m-d H:i:s')){if(!hash_equals($m['revision_offer']['fingerprint'],$f['fingerprint']))throw new RuntimeException('Önce eski sunumu iptal edip yeniden hazırlayın.');$p->commit();return;}
        $s=$p->prepare('SELECT q.*,c.name,c.company,c.email,c.phone,c.address FROM quotes q JOIN customers c ON c.id=q.customer_id WHERE q.id=?');$s->execute([$id]);$display=$s->fetch(PDO::FETCH_ASSOC);
        if($display['valid_until']&&$display['valid_until']<date('Y-m-d'))throw new RuntimeException('Teklifin geçerlilik tarihi dolmuş.');
        $expires=date('Y-m-d H:i:s',time()+7*86400);if($display['valid_until'])$expires=min($expires,$display['valid_until'].' 23:59:59');
        $s=$p->prepare('SELECT * FROM quote_details WHERE quote_id=?');$s->execute([$id]);$details=$s->fetch(PDO::FETCH_ASSOC)?:[];
        $m['revision_offer']=['state'=>'offered','nonce'=>bin2hex(random_bytes(32)),'expires_at'=>$expires,'fingerprint'=>$f['fingerprint'],'display'=>$display,'details'=>$details,'parent_id'=>$parent,'parent_no'=>ekLockQuote($p,$parent)['quote_no'],'finance'=>['currency'=>$f['currency'],'total_cents'=>$f['candidate_cents'],'paid_cents'=>$f['prior_paid_cents'],'due_cents'=>$f['additional_cents'],'refund_cents'=>$f['refund_cents']]];
        $rm['revision_pending_id']=$id;ekWriteRevisionMeta($p,$root,$rm);ekWriteRevisionMeta($p,$id,$m);
        ekRevisionEvent($p,$id,'revision_offer_created',$actor,['parent_id'=>$parent,'fingerprint'=>$f['fingerprint'],'expires_at'=>$expires]);$p->commit();
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
function ekRevokeRevision(PDO $p,int $id,int $actor):void {
    if($actor<1||$p->inTransaction())throw new RuntimeException('Geçersiz iptal isteği.');
    $p->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$p->beginTransaction();
    try{$f=ekLockRevisionChain($p,$id);$m=ekRevisionMeta($p,$id);if(!empty($m['revision_activated']))throw new RuntimeException('Kabul edilen revizyon iptal edilemez.');
        if(($m['revision_offer']['state']??'')!=='offered')throw new RuntimeException('Açık sunum bulunamadı.');
        $root=end($f['chain_ids']);$rm=ekRevisionMeta($p,$root);if((int)($rm['revision_pending_id']??0)===$id)unset($rm['revision_pending_id']);
        $m['revision_offer']['state']='revoked';ekWriteRevisionMeta($p,$root,$rm);ekWriteRevisionMeta($p,$id,$m);ekRevisionEvent($p,$id,'revision_offer_revoked',$actor,[]);$p->commit();
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
function ekRevisionDecision(PDO $p,int $id,string $token,string $secret,string $decision):void {
    if($p->inTransaction()||!in_array($decision,['accepted','rejected'],true))throw new RuntimeException('Geçersiz yanıt.');
    $p->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$p->beginTransaction();
    try{
        $f=ekLockRevisionChain($p,$id);$m=ekRevisionMeta($p,$id);$o=$m['revision_offer']??[];
        if(empty($o['nonce'])||!hash_equals(ekRevisionOfferToken($id,$o,$secret),$token))throw new RuntimeException('Revizyon bağlantısı geçersiz.');
        if(($o['state']??'')===$decision){$p->commit();return;}
        if(($o['state']??'')!=='offered'||($o['expires_at']??'')<=date('Y-m-d H:i:s'))throw new RuntimeException('Revizyon sunumu kapalı veya süresi dolmuş.');
        $parent=(int)$m['revision_parent_id'];$pm=ekRevisionMeta($p,$parent);$root=end($f['chain_ids']);$rm=ekRevisionMeta($p,$root);
        if((int)($rm['revision_pending_id']??0)!==$id||!empty($pm['revision_effective_child_id']))throw new RuntimeException('Kaynak teklifin geçerli revizyonu değişti.');
        if($decision==='accepted'&&($f['blocks']||!hash_equals($o['fingerprint'],$f['fingerprint'])))throw new RuntimeException('Teklif veya tahsilat değişmiş; yeni finans incelemesi ve sunum gerekir.');
        $m['revision_offer']['state']=$decision;$m['revision_offer']['responded_at']=date('Y-m-d H:i:s');unset($rm['revision_pending_id']);
        if($decision==='accepted'){
            $m['revision_activated']=['parent_id'=>$parent,'accepted_at'=>date('Y-m-d H:i:s'),'offer_fingerprint'=>$o['fingerprint']];
            $pm['revision_effective_child_id']=$id;
            // Root and immediate parent can be the same row; merge their changes.
            if($root===$parent)$pm=array_merge($rm,$pm);unset($pm['revision_pending_id']);
            ekWriteRevisionMeta($p,$root,$rm);ekWriteRevisionMeta($p,$parent,$pm);ekWriteRevisionMeta($p,$id,$m);
            $s=$p->prepare("UPDATE quotes SET status='accepted' WHERE id=? AND status='draft'");$s->execute([$id]);if($s->rowCount()!==1)throw new RuntimeException('Revizyon durumu değişmiş.');
            require_once __DIR__.'/moving-workflow.php';require_once __DIR__.'/quote-richtext.php';
            $q=ekLockQuote($p,$id);$q['name']=$o['display']['name'];ekAcceptanceDocuments($p,$q,'revision_customer');
            ekRevisionEvent($p,$parent,'revision_superseded',null,['effective_quote_id'=>$id]);
        }else{ekWriteRevisionMeta($p,$root,$rm);ekWriteRevisionMeta($p,$id,$m);}
        ekRevisionEvent($p,$id,'revision_customer_'.$decision,null,['parent_id'=>$parent,'offer_fingerprint'=>$o['fingerprint']]);$p->commit();
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
// Every accepted family has one effective leaf; receipts stay on original IDs.
function ekFinancialAccount(PDO $p,int $id):array {
    if(!$p->inTransaction()){$p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->beginTransaction();try{$result=ekFinancialAccount($p,$id);$p->commit();return $result;}catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}}
    $quote=$p->prepare('SELECT * FROM quotes WHERE id=?');
    $payments=$p->prepare('SELECT currency,amount_cents FROM ek_quote_payments WHERE quote_id=?');
    return ekResolveFinancialAccount($id,fn(int $key)=>ekRevisionMeta($p,$key),
        function(int $key)use($quote){$quote->execute([$key]);return $quote->fetch(PDO::FETCH_ASSOC);},
        function(int $key)use($payments){$payments->execute([$key]);return $payments->fetchAll(PDO::FETCH_ASSOC);});
}
function ekResolveFinancialAccount(int $id,callable $metadata,callable $quote,callable $payments):array {
    $ids=[];$next=$id;
    for($depth=0;$depth<64;$depth++){
        if(isset($ids[$next]))throw new RuntimeException('Geçersiz finans zinciri.');$ids[$next]=true;
        $m=$metadata($next);$child=(int)($m['revision_effective_child_id']??0);if(!$child)break;
        $cm=$metadata($child);if((int)($cm['revision_activated']['parent_id']??0)!==$next)throw new RuntimeException('Finans geçişi doğrulanamadı.');$next=$child;
    }
    if($depth===64)throw new RuntimeException('Finans zinciri çok uzun.');
    $effective=$next;$chain=[];$paid=0;$mismatch=false;$q=null;
    while($next){
        if(isset($chain[$next])||count($chain)>=64)throw new RuntimeException('Geçersiz finans zinciri.');
        $row=$quote($next);if(!$row)throw new RuntimeException('Teklif bulunamadı.');
        if(!$q)$q=$row;
        if($row['currency']!==$q['currency']||(int)$row['customer_id']!==(int)$q['customer_id'])throw new RuntimeException('Finans zinciri müşteri/para birimi uyumsuz.');
        $chain[$next]=true;$m=$metadata($next);
        foreach($payments($next) as $payment){if($payment['currency']!==$q['currency'])$mismatch=true;else $paid+=(int)$payment['amount_cents'];}
        $parent=(int)($m['revision_activated']['parent_id']??0);
        if($parent){$pm=$metadata($parent);if((int)($pm['revision_effective_child_id']??0)!==$next)throw new RuntimeException('Finans geçişi doğrulanamadı.');}
        $next=$parent;
    }
    return ['effective_id'=>$effective,'quote'=>$q,'ids'=>array_keys($chain),'paid_cents'=>$paid,'remaining_cents'=>ekRevisionCents((string)$q['total'])-$paid,'mismatch'=>$mismatch];
}
function ekEffectiveFinancialRows(PDO $p,bool $includeTest=false):array {
    if(!$p->inTransaction()){$p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->beginTransaction();try{$result=ekEffectiveFinancialRows($p,$includeTest);$p->commit();return $result;}catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}}
    $rows=$p->query("SELECT q.id,q.quote_no,q.total,q.currency,q.status,q.valid_until,c.name FROM quotes q JOIN customers c ON c.id=q.customer_id WHERE q.status IN ('sent','accepted','priced')".($includeTest?'':" AND c.name NOT LIKE '[TEST]%'").' ORDER BY q.id DESC')->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows)return [];
    $quotes=$p->query('SELECT id,customer_id,currency,total FROM quotes')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
    $payloads=$p->query('SELECT quote_id,payload FROM moving_quote_meta')->fetchAll(PDO::FETCH_KEY_PAIR);
    $payments=$p->query('SELECT quote_id,currency,SUM(amount_cents) AS amount_cents FROM ek_quote_payments GROUP BY quote_id,currency')->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_ASSOC);
    $metadata=static fn(int $id):array=>ekDecodeRevisionMeta($payloads[$id]??null);
    $quote=static fn(int $id)=>$quotes[$id]??false;
    $receipts=static fn(int $id):array=>$payments[$id]??[];
    $result=[];
    foreach($rows as $row){
        $id=(int)$row['id'];$m=$metadata($id);
        if(!empty($m['revision_effective_child_id']))continue;
        if(!empty($m['revision_parent_id'])&&empty($m['revision_activated']))continue;
        $a=ekResolveFinancialAccount($id,$metadata,$quote,$receipts);
        $row['paid_cents']=$a['paid_cents'];$row['mismatch']=$a['mismatch'];$result[]=$row;
    }
    return $result;
}
