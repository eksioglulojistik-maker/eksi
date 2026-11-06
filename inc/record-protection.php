<?php
// Shared lock order: quote -> customer (if needed) -> contract -> share -> signature.
// No schema changes and no network side effects belong in these helpers.
function ekTableExists(PDO $pdo, string $table): bool {
    if (!in_array($table, ['ek_contract_signatures','quote_archives'], true)) {
        throw new LogicException('Geçersiz tablo kontrolü.');
    }
    return (bool)$pdo->query('SHOW TABLES LIKE '.$pdo->quote($table))->fetchColumn();
}
function ekLockQuote(PDO $pdo, int $id): array {
    if (!$pdo->inTransaction()) throw new LogicException('Kayıt kilidi transaction gerektirir.');
    $s=$pdo->prepare('SELECT * FROM quotes WHERE id=? FOR UPDATE');$s->execute([$id]);
    $q=$s->fetch(PDO::FETCH_ASSOC);
    if (!$q) throw new RuntimeException('Teklif bulunamadı.');
    return $q;
}
function ekQuoteHasSignature(PDO $pdo, int $id): bool {
    if (!ekTableExists($pdo,'ek_contract_signatures')) return false;
    $s=$pdo->prepare('SELECT id FROM ek_contract_signatures WHERE quote_id=?');$s->execute([$id]);
    return (bool)$s->fetchColumn();
}
function ekQuoteProtectionReason(PDO $pdo, array $q): string {
    $s=$pdo->prepare('SELECT payload FROM moving_quote_meta WHERE quote_id=?');$s->execute([$q['id']]);$m=json_decode($s->fetchColumn()?:'{}',true,512,JSON_THROW_ON_ERROR);
    if(!empty($m['revision_effective_child_id']))return 'Bu kayıt tarihsel kaynaktır; geçerli revizyon korunuyor.';
    if(($m['revision_offer']['state']??'')==='offered')return 'Müşteriye sunulan revizyon düzenlenemez. Önce sunumu iptal edin.';
    if (in_array(strtolower((string)$q['status']), ['accepted','approved','completed'],true)) {
        return 'Onaylanmış teklif korunuyor. Değişiklik için yeni taslak revizyon oluşturun.';
    }
    if (ekQuoteHasSignature($pdo,(int)$q['id'])) {
        return 'Bu teklife bağlı imza bulunduğu için kayıt korunuyor. Yeni taslak revizyon oluşturun.';
    }
    return '';
}
function ekAssertQuoteEditable(PDO $pdo, array $lockedQuote): void {
    if (!$pdo->inTransaction()) throw new LogicException('Yazma kontrolü transaction gerektirir.');
    $reason=ekQuoteProtectionReason($pdo,$lockedQuote);
    if ($reason!=='') throw new RuntimeException($reason);
}
function ekLockEditableContract(PDO $pdo, int $id): ?array {
    $q=ekLockQuote($pdo,$id);
    $m=ekRevisionMeta($pdo,$id);if(!empty($m['revision_effective_child_id']))throw new RuntimeException('Tarihsel kaynak sözleşmesi değiştirilemez.');if(($m['revision_offer']['state']??'')==='offered')throw new RuntimeException('Müşteriye sunulan revizyon sözleşmesi değiştirilemez.');
    $s=$pdo->prepare('SELECT * FROM v11_contracts WHERE quote_id=? FOR UPDATE');$s->execute([$id]);
    $contract=$s->fetch(PDO::FETCH_ASSOC)?:null;
    // Any signature protects the record, including legacy hash mismatches.
    if (ekQuoteHasSignature($pdo,$id)) {
        throw new RuntimeException('İmzalı sözleşme değiştirilemez veya silinemez. Yeni teklif revizyonu kullanın.');
    }
    return $contract;
}
function ekContractVersion(?array $contract): string {
    return $contract ? hash('sha256',$contract['language']."\n".$contract['body']) : 'new';
}
function ekContractDisplayData(array $q, array $customer, array $contract): array {
    $date=substr((string)($contract['created_at']??''),0,10);
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))$date=date('Y-m-d');
    return ['quote_id'=>(int)$q['id'],'quote_no'=>(string)$q['quote_no'],
        'name'=>(string)$customer['name'],'company'=>(string)($customer['company']??''),
        'origin'=>(string)$q['origin'],'destination'=>(string)$q['destination'],
        'volume'=>number_format((float)$q['volume'],3,'.',''),
        'language'=>(string)$contract['language'],'body'=>(string)$contract['body'],
        'document_date'=>$date];
}
function ekDisplayHash(array $data): string {
    // MySQL JSON normalizes object key order; hash the canonical flat display map.
    ksort($data,SORT_STRING);
    return hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function ekLoadContractDisplay(PDO $pdo, int $id): array {
    $s=$pdo->prepare('SELECT * FROM quotes WHERE id=?');$s->execute([$id]);$q=$s->fetch(PDO::FETCH_ASSOC);
    if(!$q)throw new RuntimeException('Teklif bulunamadı.');
    $s=$pdo->prepare('SELECT name,company FROM customers WHERE id=?');$s->execute([$q['customer_id']]);$customer=$s->fetch(PDO::FETCH_ASSOC);
    $s=$pdo->prepare('SELECT * FROM v11_contracts WHERE quote_id=?');$s->execute([$id]);$contract=$s->fetch(PDO::FETCH_ASSOC);
    if(!$customer||!$contract)throw new RuntimeException('Sözleşme bilgileri bulunamadı.');
    return ekContractDisplayData($q,$customer,$contract);
}
function ekSignedDisplay(PDO $pdo, int $id, string $contractHash): ?array {
    $s=$pdo->prepare('SELECT payload FROM moving_quote_meta WHERE quote_id=?');$s->execute([$id]);
    $meta=json_decode($s->fetchColumn()?:'{}',true,512,JSON_THROW_ON_ERROR);
    if(!is_array($meta))throw new RuntimeException('Sözleşme arşiv bilgileri okunamadı.');
    $snapshot=$meta['signed_contract_snapshot']??null;
    if($snapshot===null)return null; // Do not invent historical data for legacy signatures.
    if(!is_array($snapshot)||($snapshot['schema']??null)!==1||!is_array($snapshot['display']??null)||
        !is_string($snapshot['contract_hash']??null)||!hash_equals($contractHash,$snapshot['contract_hash'])||
        !is_string($snapshot['display_hash']??null)||!hash_equals(ekDisplayHash($snapshot['display']),$snapshot['display_hash'])||
        (int)($snapshot['display']['quote_id']??0)!==$id)throw new RuntimeException('İmzalı belge arşiv bütünlüğü doğrulanamadı.');
    return $snapshot['display'];
}
function ekLockCustomerForUpdate(PDO $pdo, int $id, string $name, string $company): void {
    if(!$pdo->inTransaction())throw new LogicException('Müşteri değişikliği transaction gerektirir.');
    $s=$pdo->prepare('SELECT id FROM quotes WHERE customer_id=? ORDER BY id FOR UPDATE');$s->execute([$id]);$ids=$s->fetchAll(PDO::FETCH_COLUMN);
    $s=$pdo->prepare('SELECT name,company FROM customers WHERE id=? FOR UPDATE');$s->execute([$id]);$old=$s->fetch(PDO::FETCH_ASSOC);
    if(!$old)throw new RuntimeException('Müşteri bulunamadı.');
    if($old['name']===$name&&(string)$old['company']===$company)return;
    foreach($ids as $pendingQuote){$pendingMeta=ekRevisionMeta($pdo,(int)$pendingQuote);if(($pendingMeta['revision_offer']['state']??'')==='offered')throw new RuntimeException('Müşteriye sunulmuş revizyon varken ad/şirket değiştirilemez. Önce sunumu iptal edin.');}
    if(!ekTableExists($pdo,'ek_contract_signatures'))return;
    foreach($ids as $qid){
        $s=$pdo->prepare('SELECT contract_hash FROM ek_contract_signatures WHERE quote_id=?');$s->execute([$qid]);$hash=$s->fetchColumn();
        if(is_string($hash)&&ekSignedDisplay($pdo,(int)$qid,$hash)===null)throw new RuntimeException('Bu müşterinin eski imzalı belgesinde sabit arşiv kopyası yok. Ad/şirket değişikliği engellendi; iletişim bilgileri güncellenebilir.');
    }
}
function ekQuoteState(PDO $pdo, array $q): array {
    $s=$pdo->prepare('SELECT * FROM quote_details WHERE quote_id=?');$s->execute([$q['id']]);$d=$s->fetch(PDO::FETCH_ASSOC)?:[];
    $s=$pdo->prepare('SELECT payload FROM moving_quote_meta WHERE quote_id=?');$s->execute([$q['id']]);
    $meta=json_decode($s->fetchColumn()?:'{}',true,512,JSON_THROW_ON_ERROR);
    if (!is_array($meta)) throw new RuntimeException('Teklif ek bilgileri okunamadı.');
    return ['quote'=>$q,'details'=>$d,'moving'=>$meta];
}
function ekQuoteStateVersion(array $state): string {
    return hash('sha256',json_encode([$state['quote'],$state['details'],$state['moving']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function ekCreateDraftRevision(PDO $pdo, int $sourceId, string $expectedVersion, int $actorId): int {
    if ($actorId<1 || $pdo->inTransaction()) throw new LogicException('Geçersiz revizyon bağlamı.');
    $pdo->beginTransaction();
    try {
        $source=ekLockQuote($pdo,$sourceId);
        if (ekTableExists($pdo,'quote_archives')) {
            $s=$pdo->prepare('SELECT 1 FROM quote_archives WHERE quote_id=?');$s->execute([$sourceId]);
            if ($s->fetchColumn()) throw new RuntimeException('Arşivdeki teklif için önce geri yükleme gerekir.');
        }
        $state=ekQuoteState($pdo,$source);
        if(!empty($state['moving']['revision_effective_child_id'])||($state['moving']['revision_offer']['state']??'')==='offered')throw new RuntimeException('Yeni revizyon için geçerli teklifi açın; açık sunumu önce iptal edin.');
        if (!hash_equals(ekQuoteStateVersion($state),$expectedVersion)) throw new RuntimeException('Kaynak teklif değişmiş. Sayfayı yenileyin.');
        $fields=['customer_id','mode','trade_direction','origin','destination','volume','weight','pieces','unit_price','extra_price','discount','tax_rate','total','currency','status','valid_until','notes','terms_snapshot','quote_no','created_by'];
        $copy=$source;$copy['status']='draft';$copy['valid_until']=null;$copy['quote_no']=nextNumber($pdo);$copy['created_by']=$actorId;
        $s=$pdo->prepare('INSERT INTO quotes ('.implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');
        $s->execute(array_map(fn($key)=>array_key_exists($key,$copy)?$copy[$key]:'', $fields));$newId=(int)$pdo->lastInsertId();
        $d=$state['details'];
        $s=$pdo->prepare('INSERT INTO quote_details(quote_id,service_scope,payment_terms,delivery_time,additional_terms,transport_process) VALUES(?,?,?,?,?,?)');
        $s->execute([$newId,$d['service_scope']??'',$d['payment_terms']??'',$d['delivery_time']??'',$d['additional_terms']??'',$d['transport_process']??'']);
        $meta=$state['moving'];foreach(['signed_contract_snapshot','revision_offer','revision_activated','revision_effective_child_id','revision_pending_id'] as $systemKey)unset($meta[$systemKey]);$meta['revision_parent_id']=$sourceId;$meta['revision_root_id']=(int)($meta['revision_root_id']??$sourceId);
        $meta['revision_created_at']=date('c');$meta['revision_actor_id']=$actorId;
        $s=$pdo->prepare('INSERT INTO moving_quote_meta(quote_id,payload) VALUES(?,?)');$s->execute([$newId,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $s=$pdo->prepare('INSERT INTO v11_inventory(quote_id,room,item_name,quantity,unit_volume,notes) SELECT ?,room,item_name,quantity,unit_volume,notes FROM v11_inventory WHERE quote_id=?');$s->execute([$newId,$sourceId]);
        // Financial movements, signatures, contracts and schedules are deliberately not copied.
        $s=$pdo->prepare('INSERT INTO v11_quote_events(quote_id,event_type,actor_id,payload) VALUES(?,?,?,?)');
        $s->execute([$sourceId,'draft_revision_created',$actorId,json_encode(['new_quote_id'=>$newId],JSON_THROW_ON_ERROR)]);
        $s->execute([$newId,'draft_revision_of',$actorId,json_encode(['source_quote_id'=>$sourceId],JSON_THROW_ON_ERROR)]);
        $pdo->commit();return $newId;
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
}
function ekStoreContractSignature(PDO $pdo, int $id, string $expectedHash, string $name, string $png, int $actorId, string $ipHash, ?string $tokenHash=null, ?string $expectedDisplayHash=null): void {
    if ($pdo->inTransaction()) throw new LogicException('İmza işlemi ayrı transaction gerektirir.');
    if (!preg_match('/^[a-f0-9]{64}$/D',$expectedHash) || trim($name)==='' || mb_strlen($name)>190 || !preg_match('/^[a-f0-9]{64}$/D',$ipHash)) throw new RuntimeException('İmza bilgileri geçersiz.');
    $dim=@getimagesizefromstring($png);
    if(strlen($png)<250||strlen($png)>110000||substr($png,0,8)!=="\x89PNG\r\n\x1a\n"||!$dim||$dim[2]!==IMAGETYPE_PNG||$dim[0]>1600||$dim[1]>700)throw new RuntimeException('Geçerli PNG imzası alınamadı.');
    if(($tokenHash===null&&$actorId<1)||($tokenHash!==null&&($actorId!==0||!preg_match('/^[a-f0-9]{64}$/D',$tokenHash))))throw new RuntimeException('İmza kanalı geçersiz.');
    if (!ekTableExists($pdo,'ek_contract_signatures')) throw new RuntimeException('İmza tablosu hazır değil. Sistem Kontrolü bölümünü inceleyin.');
    $pdo->beginTransaction();
    try {
        $q=ekLockQuote($pdo,$id);
        $revisionMeta=ekRevisionMeta($pdo,$id);if(!empty($revisionMeta['revision_effective_child_id']))throw new RuntimeException('Tarihsel kaynak sözleşmesi yeniden imzalanamaz.');if(!empty($revisionMeta['revision_parent_id'])&&empty($revisionMeta['revision_activated']))throw new RuntimeException('Revizyon müşteri kabulünden önce imzalanamaz.');
        $s=$pdo->prepare('SELECT name,company FROM customers WHERE id=? FOR UPDATE');$s->execute([$q['customer_id']]);$customer=$s->fetch(PDO::FETCH_ASSOC);
        if(!$customer)throw new RuntimeException('Müşteri bulunamadı.');
        $s=$pdo->prepare('SELECT * FROM v11_contracts WHERE quote_id=? FOR UPDATE');$s->execute([$id]);$contract=$s->fetch(PDO::FETCH_ASSOC)?:null;
        if (!$contract || !hash_equals(ekContractVersion($contract),$expectedHash)) throw new RuntimeException('Sözleşme değişmiş. Güncel belgeyi yeniden açın.');
        $display=ekContractDisplayData($q,$customer,$contract);
        if(!is_string($expectedDisplayHash)||!hash_equals(ekDisplayHash($display),$expectedDisplayHash))throw new RuntimeException('Belgedeki müşteri veya taşıma bilgileri değişmiş. Belgeyi yeniden inceleyin.');
        if ($tokenHash!==null) {
            $s=$pdo->prepare('SELECT contract_hash,revoked_at,(expires_at>NOW()) AS unexpired FROM ek_contract_share_links WHERE token_hash=? AND quote_id=? FOR UPDATE');$s->execute([$tokenHash,$id]);$link=$s->fetch(PDO::FETCH_ASSOC);
            if (!$link || $link['revoked_at']!==null || !(int)$link['unexpired'] || !hash_equals($link['contract_hash'],$expectedHash)) throw new RuntimeException('İmza bağlantısı geçersiz, iptal edilmiş veya süresi dolmuş.');
        }
        if (ekQuoteHasSignature($pdo,$id)) throw new RuntimeException('Bu sözleşme için imza zaten mevcut. Mevcut imza korunuyor.');
        $s=$pdo->prepare('SELECT payload FROM moving_quote_meta WHERE quote_id=? FOR UPDATE');$s->execute([$id]);$raw=$s->fetchColumn();
        $meta=$raw===false?[]:json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($meta))throw new RuntimeException('Sözleşme arşiv bilgileri okunamadı.');
        $meta['signed_contract_snapshot']=['schema'=>1,'contract_hash'=>$expectedHash,'display_hash'=>ekDisplayHash($display),'display'=>$display];
        $s=$pdo->prepare('INSERT INTO moving_quote_meta(quote_id,payload) VALUES(?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload)');$s->execute([$id,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $s=$pdo->prepare('INSERT INTO ek_contract_signatures(quote_id,contract_hash,signer_name,image_png,signed_at,signed_by,ip_hash) VALUES(?,?,?,?,NOW(),?,?)');
        $s->execute([$id,$expectedHash,$name,$png,$actorId,$ipHash]);
        $s=$pdo->prepare('INSERT INTO v11_quote_events(quote_id,event_type,actor_id,payload) VALUES(?,?,?,?)');
        $s->execute([$id,'contract_signed',$actorId?:null,json_encode(['contract_hash'=>$expectedHash,'channel'=>$tokenHash===null?'admin':'customer'],JSON_THROW_ON_ERROR)]);
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
}

function ekMarkQuoteMailSending(PDO $p,int $id,int $actor): void {
    if($p->inTransaction()||$actor<1)throw new RuntimeException('Gönderim bağlamı geçersiz.');
    $p->beginTransaction();try{ekLockQuote($p,$id);
        $s=$p->prepare('SELECT 1 FROM quote_archives WHERE quote_id=?');$s->execute([$id]);if($s->fetchColumn())throw new RuntimeException('Arşivli teklif gönderilemez. Önce kaydı geri yükleyin.');
        ekRevisionEvent($p,$id,'smtp_sending',$actor,[]);$p->commit();
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}

require_once __DIR__.'/revision-transition.php';

function ekSnapshotQuote(PDO $pdo,array $lockedQuote,array $lockedDetails,array $lockedExtra,int $actorId):void {
    $id=(int)$lockedQuote['id'];
    $check=$pdo->query("SHOW TABLES LIKE 'v11_quote_revisions'")->fetchColumn();
    if(!$check)throw new RuntimeException('Revizyon tablosu eksik. Sistem Kontrolü bölümünü açın.');
    $revNo=$pdo->prepare('SELECT COALESCE(MAX(revision_no),0)+1 FROM v11_quote_revisions WHERE quote_id=?');
    $revNo->execute([$id]);$nextRevision=(int)$revNo->fetchColumn();
    $snapshot=['quote'=>$lockedQuote,'details'=>$lockedDetails,'moving'=>$lockedExtra];
    $revInsert=$pdo->prepare('INSERT INTO v11_quote_revisions(quote_id,revision_no,actor_id,snapshot) VALUES(?,?,?,?)');
    $revInsert->execute([$id,$nextRevision,$actorId,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}
