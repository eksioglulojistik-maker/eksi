<?php
require_once __DIR__.'/revision-transition.php';
function ekMoveStatus(string $status):string {
    return ['planned'=>'Planlandı','loading'=>'Yükleniyor','in_transit'=>'Yolda','delivered'=>'Teslim edildi','cancelled'=>'İptal edildi'][$status]??$status;
}
function ekMoveContext(PDO $p,int $id):array {
    if(!$p->inTransaction()){$p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->beginTransaction();try{$result=ekMoveContext($p,$id);$p->commit();return $result;}catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}}
    if($id<1)throw new RuntimeException('Geçerli bir teklif seçin.');
    $next=$id;$seen=[];
    while(true){
        if(isset($seen[$next])||count($seen)>=64)throw new RuntimeException('Taşınma dosyası ilişkisi geçersiz.');$seen[$next]=true;
        $m=ekRevisionMeta($p,$next);$child=(int)($m['revision_effective_child_id']??0);if(!$child)break;
        $cm=ekRevisionMeta($p,$child);if((int)($cm['revision_activated']['parent_id']??0)!==$next)throw new RuntimeException('Geçerli revizyon doğrulanamadı.');$next=$child;
    }
    $effectiveId=$next;$chain=[];$quotes=[];$metas=[];
    while($next){
        if(isset($quotes[$next])||count($quotes)>=64)throw new RuntimeException('Taşınma dosyası zinciri geçersiz.');
        $s=$p->prepare('SELECT q.*,c.name,c.company,c.phone,c.email FROM quotes q JOIN customers c ON c.id=q.customer_id WHERE q.id=?');$s->execute([$next]);$q=$s->fetch(PDO::FETCH_ASSOC);
        if(!$q)throw new RuntimeException('Taşınma dosyasında teklif veya müşteri eksik.');
        $m=ekRevisionMeta($p,$next);
        if(!empty($m['revision_parent_id'])&&empty($m['revision_activated']))throw new RuntimeException('Revizyon taslağı henüz müşteri tarafından kabul edilmedi. Operasyon için geçerli kaynak teklifi açın.');
        if($quotes&&(int)$q['customer_id']!==(int)$quotes[$effectiveId]['customer_id'])throw new RuntimeException('Dosya zincirindeki müşteriler uyuşmuyor.');
        if(!empty($m['revision_activated'])&&$q['status']!=='accepted')throw new RuntimeException('Geçerli revizyonun durumu doğrulanamadı.');
        $chain[]=$next;$quotes[$next]=$q;$metas[$next]=$m;$parent=(int)($m['revision_activated']['parent_id']??0);
        if($parent){$pm=ekRevisionMeta($p,$parent);if((int)($pm['revision_effective_child_id']??0)!==$next)throw new RuntimeException('Kaynak teklif ilişkisi doğrulanamadı.');}$next=$parent;
    }
    $root=end($chain);$marks=implode(',',array_fill(0,count($chain),'?'));
    $s=$p->prepare('SELECT * FROM v11_moves WHERE quote_id IN ('.$marks.') ORDER BY id');$s->execute($chain);$moves=$s->fetchAll(PDO::FETCH_ASSOC);
    if(count($moves)>1)throw new RuntimeException('Bu taşınma zincirinde birden fazla plan var. Kayıtlar korunuyor; birleştirmeden önce yönetici incelemesi gerekir.');
    $move=$moves[0]??null;$anchor=$move?(int)$move['quote_id']:$root;
    $version=hash('sha256',json_encode(['quotes'=>$quotes,'meta'=>$metas,'move'=>$move],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    return ['effective_id'=>$effectiveId,'root_id'=>$root,'anchor_id'=>$anchor,'ids'=>$chain,'quote'=>$quotes[$effectiveId],
        'meta'=>$metas[$effectiveId],'quotes'=>$quotes,'move'=>$move,'version'=>$version];
}
function ekSaveMovePlan(PDO $p,int $id,string $expected,int $actor,string $action,array $input=[]):void {
    if($p->inTransaction()||$actor<1||!in_array($action,['save','cancel'],true)||!preg_match('/^[a-f0-9]{64}$/D',$expected))throw new RuntimeException('Plan isteği geçersiz. Takvimi yenileyin.');
    $p->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$p->beginTransaction();
    try{
        $before=ekMoveContext($p,$id);$ids=$before['ids'];sort($ids,SORT_NUMERIC);foreach($ids as $qid)ekLockQuote($p,$qid);
        $s=$p->prepare('SELECT id FROM v11_moves WHERE quote_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY quote_id FOR UPDATE');$s->execute($ids);$s->fetchAll();
        $ctx=ekMoveContext($p,$id);
        if($ctx['ids']!==$before['ids']||!hash_equals($expected,$ctx['version']))throw new RuntimeException('Teklif veya taşınma planı değişmiş. Güncel dosyayı açıp tekrar deneyin.');
        if(ekTableExists($p,'quote_archives')){$s=$p->prepare('SELECT quote_id FROM quote_archives WHERE quote_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$s->execute($ids);if($s->fetchColumn())throw new RuntimeException('Arşivli kaydın planı değiştirilemez.');}
        $old=$ctx['move'];
        if($action==='cancel'){
            if(!$old)throw new RuntimeException('İptal edilecek plan bulunamadı.');
            if($old['status']==='cancelled'){$p->commit();return;}
            $s=$p->prepare("UPDATE v11_moves SET status='cancelled' WHERE id=? AND quote_id=?");$s->execute([$old['id'],$ctx['anchor_id']]);
            $data=['effective_quote_id'=>$ctx['effective_id'],'previous'=>$old,'status'=>'cancelled'];
        }else{
            foreach(['loading_date','delivery_date','status','notes'] as $field)if(!is_string($input[$field]??null))throw new RuntimeException('Plan alanları geçersiz.');
            $loading=$input['loading_date'];$delivery=$input['delivery_date'];$status=$input['status'];$notes=trim($input['notes']);
            if(!ekDate($loading)||($delivery!==''&&(!ekDate($delivery)||$delivery<$loading)))throw new RuntimeException('Yükleme veya teslim tarihi geçersiz.');
            if(!in_array($status,['planned','loading','in_transit','delivered','cancelled'],true)||mb_strlen($notes)>3000)throw new RuntimeException('Plan durumu veya notu geçersiz.');
            $s=$p->prepare('INSERT INTO v11_moves(quote_id,loading_date,delivery_date,status,notes) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE loading_date=VALUES(loading_date),delivery_date=VALUES(delivery_date),status=VALUES(status),notes=VALUES(notes)');
            $s->execute([$ctx['anchor_id'],$loading,$delivery?:null,$status,$notes]);
            $data=['effective_quote_id'=>$ctx['effective_id'],'previous'=>$old,'loading_date'=>$loading,'delivery_date'=>$delivery?:null,'status'=>$status,'notes'=>$notes];
        }
        ekRevisionEvent($p,$ctx['anchor_id'],$action==='cancel'?'move_cancelled':'move_updated',$actor,$data);$p->commit();
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
function ekMovingFile(PDO $p,int $id,bool $finance):array {
    if($p->inTransaction())throw new LogicException('Dosya görünümü ayrı transaction gerektirir.');
    $p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->beginTransaction();
    try{
        $ctx=ekMoveContext($p,$id);$s=$p->prepare('SELECT room,item_name,quantity,unit_volume,notes FROM v11_inventory WHERE quote_id=? ORDER BY room,id');$s->execute([$ctx['effective_id']]);$inventory=$s->fetchAll(PDO::FETCH_ASSOC);
        $signatureKnown=ekTableExists($p,'ek_contract_signatures');$contracts=[];foreach($ctx['ids'] as $qid){$s=$p->prepare('SELECT id,language,created_at FROM v11_contracts WHERE quote_id=?');$s->execute([$qid]);$contract=$s->fetch(PDO::FETCH_ASSOC);if($contract)$contracts[]=['quote_id'=>$qid,'quote_no'=>$ctx['quotes'][$qid]['quote_no'],'contract'=>$contract,'signed'=>ekQuoteHasSignature($p,$qid),'signature_known'=>$signatureKnown,'current'=>$qid===$ctx['effective_id']];}
        $balance=null;if($finance){try{$balance=ekFinancialAccount($p,$ctx['effective_id']);}catch(Throwable $balanceError){error_log('Move file balance: '.$balanceError->getMessage());}}
        $p->commit();return ['context'=>$ctx,'inventory'=>$inventory,'contracts'=>$contracts,'balance'=>$balance];
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
