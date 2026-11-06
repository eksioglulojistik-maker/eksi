<?php
require_once __DIR__.'/record-protection.php';
function ekDeleteIdentifier(string $name):string {
    if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$name))throw new RuntimeException('Bağımlılık bilgileri doğrulanamadı.');return '`'.$name.'`';
}
function ekDeleteSchema(PDO $p):array {
    $tables=$p->query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE=\'BASE TABLE\' ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_KEY_PAIR);
    $columns=[];foreach($p->query('SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC) as $r)$columns[$r['TABLE_NAME']][]=$r['COLUMN_NAME'];
    $foreign=$p->query('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC);
    return ['tables'=>$tables,'columns'=>$columns,'foreign'=>$foreign];
}
function ekPermanentDeletePlan(PDO $p,string $kind,int $id,bool $lock=false):array {
    if(!$p->inTransaction()){$p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->beginTransaction();try{$v=ekPermanentDeletePlan($p,$kind,$id);$p->commit();return $v;}catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}}
    $entity=['quote'=>'quotes','customer'=>'customers','contract'=>'v11_contracts'][$kind]??null;
    if(!$entity||$id<1)throw new RuntimeException('Silme hedefi geçersiz.');
    $schema=ekDeleteSchema($p);$blocks=[];$counts=[];$delete=[];$evidence=[];$quoteId=0;$children=[];$related=[];
    $s=$p->prepare('SELECT * FROM '.ekDeleteIdentifier($entity).' WHERE id=?');$s->execute([$id]);$record=$s->fetch(PDO::FETCH_ASSOC);if(!$record)throw new RuntimeException('Kayıt bulunamadı veya daha önce silinmiş.');
    if($kind==='quote')$quoteId=$id;elseif($kind==='contract')$quoteId=(int)$record['quote_id'];
    if($lock){
        if($quoteId)ekLockQuote($p,$quoteId);
        $s=$p->prepare('SELECT * FROM '.ekDeleteIdentifier($entity).' WHERE id=? FOR UPDATE');$s->execute([$id]);$record=$s->fetch(PDO::FETCH_ASSOC);if(!$record)throw new RuntimeException('Kayıt değişmiş veya silinmiş.');
    }
    $title=$kind==='quote'?(string)$record['quote_no']:($kind==='customer'?(string)$record['name']:'Sözleşme #'.$id);
    $labels=['quote_details'=>'Teklif açıklamaları','moving_quote_meta'=>'Taşınma ek bilgileri','v11_inventory'=>'Eşya satırları','quote_archives'=>'Arşiv kaydı','v11_quote_revisions'=>'Taslak anlık kopyaları','v11_quote_events'=>'Teklif işlem geçmişi','ek_contract_share_links'=>'Sözleşme paylaşım bağlantıları'];
    $read=function(string $table,string $column,int $value)use($p,$lock,&$evidence,&$counts,$schema):array{
        if(!isset($schema['tables'][$table])||!in_array($column,$schema['columns'][$table]??[],true))return [];
        $s=$p->prepare('SELECT * FROM '.ekDeleteIdentifier($table).' WHERE '.ekDeleteIdentifier($column).'=?'.($lock?' FOR UPDATE':''));$s->execute([$value]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        // Fingerprints do not depend on fetch order, even on legacy unindexed tables.
        usort($rows,fn($a,$b)=>strcmp(serialize($a),serialize($b)));$evidence[$table.'|'.$column]=$rows;$counts[$table]=count($rows);return $rows;
    };
    $required=['v11_quote_events','quotes','customers'];if($quoteId)$required=array_merge($required,['quotes','moving_quote_meta','ek_quote_payments','ek_contract_signatures','v11_moves','v11_contracts']);
    foreach($required as $table)if(!isset($schema['tables'][$table]))$blocks[]='Koruma kontrolleri için gerekli kayıt altyapısı eksik; silme kapalı.';
    if($kind==='customer'){
        $s=$p->prepare("SELECT * FROM v11_quote_events WHERE quote_id=0 AND event_type='customer_archive_state' AND JSON_EXTRACT(payload,'$.customer_id')=CAST(? AS UNSIGNED) ORDER BY id".($lock?' FOR UPDATE':''));$s->execute([$id]);$evidence['archive_state']=$s->fetchAll(PDO::FETCH_ASSOC);
        foreach($schema['columns'] as $table=>$columns)if(in_array('customer_id',$columns,true)){
            $rows=$read($table,'customer_id',$id);
            foreach($rows as $row){
                if($table==='quotes'){
                    $child=ekPermanentDeletePlan($p,'quote',(int)$row['id'],$lock);$children[]=$child;
                    foreach($child['related'] as $nested)$related[]=$nested;
                    $related[]=['label'=>$row['quote_no'].' · '.(['draft'=>'Taslak','rejected'=>'Reddedildi','expired'=>'Süresi doldu','accepted'=>'Onaylandı','sent'=>'Gönderildi','priced'=>'Fiyatlandırıldı'][$row['status']]??$row['status']), 'url'=>'view.php?id='.(int)$row['id'],'protected'=>(bool)$child['blocks']];
                    foreach($child['blocks'] as $block)$blocks[]=$row['quote_no'].': '.$block;
                }else{$blocks[]='Müşteriye bağlı ek kayıt var: '.$table.' ('.count($rows).' kayıt).';break;}
            }
        }
    }else{
        $q=$kind==='quote'?$record:ekLockQuoteOrReadForDelete($p,$quoteId,$lock);
        if($kind==='contract')$title='Sözleşme #'.$id.' · '.$q['quote_no'];
        $metaRows=$read('moving_quote_meta','quote_id',$quoteId);$meta=json_decode($metaRows[0]['payload']??'{}',true,512,JSON_THROW_ON_ERROR);if(!is_array($meta))throw new RuntimeException('Taşınma bilgileri doğrulanamadı.');
        if(!empty($meta['revision_parent_id'])||!empty($meta['revision_activated'])||!empty($meta['revision_effective_child_id'])||!empty($meta['revision_pending_id'])||!empty($meta['revision_offer']))$blocks[]='Revizyon veya müşteri sunumu geçmişi bulunan kayıt korunur.';
        $allowed=$kind==='quote'?['quote_details','moving_quote_meta','v11_inventory','quote_archives','v11_quote_revisions','v11_quote_events','ek_contract_share_links']:['ek_contract_share_links'];
        foreach($schema['columns'] as $table=>$columns){
            if(!in_array('quote_id',$columns,true))continue;$rows=$read($table,'quote_id',$quoteId);
            if($kind==='quote'&&in_array($table,$allowed,true))$delete[$table]=['column'=>'quote_id','value'=>$quoteId];
            elseif($kind==='contract'&&$table==='ek_contract_share_links')$delete[$table]=['column'=>'quote_id','value'=>$quoteId];
            elseif($kind==='quote'&&$table==='v11_contracts'){foreach($rows as $cr){$child=ekPermanentDeletePlan($p,'contract',(int)$cr['id'],$lock);$children[]=$child;$related[]=['label'=>'Sözleşme #'.(int)$cr['id'],'url'=>'v11-documents.php?id='.$quoteId,'protected'=>(bool)$child['blocks']];foreach($child['blocks'] as $block)$blocks[]='Sözleşme #'.(int)$cr['id'].': '.$block;}}
            elseif($rows&&($kind==='quote'||in_array($table,['ek_quote_payments','ek_contract_signatures'],true))){$name=['ek_quote_payments'=>'Tahsilat / finans kaydı','ek_contract_signatures'=>'Dijital imza','v11_moves'=>'Operasyon kaydı'][$table]??'Ek bağlı kayıt ('.$table.')';$blocks[]=$name.': '.count($rows).' kayıt korunuyor.';}
        }
        if($kind==='quote'){
            if(!in_array($record['status'],['draft','rejected','expired'],true))$blocks[]='Yalnız taslak, reddedilmiş veya süresi dolmuş teklifler kalıcı silinebilir. Onaylı ve gönderilmiş teklifler korunur.';
            foreach($evidence['v11_quote_events|quote_id']??[] as $event)if(preg_match('/signed|accept|payment|move_|revision_offer|revision_customer|smtp/i',(string)$event['event_type']))$blocks[]='İmza, kabul, finans, operasyon veya gönderim geçmişi bulunan teklif korunur.';
        }
        // Metadata references are not SQL foreign keys. Never strand another revision.
        if(isset($schema['tables']['moving_quote_meta'])){
            foreach($p->query('SELECT quote_id,payload FROM moving_quote_meta')->fetchAll(PDO::FETCH_ASSOC) as $other){
                if((int)$other['quote_id']===$quoteId)continue;$om=json_decode($other['payload'],true,512,JSON_THROW_ON_ERROR);if(!is_array($om))throw new RuntimeException('Revizyon ilişkileri doğrulanamadı.');
                if(in_array($quoteId,[(int)($om['revision_parent_id']??0),(int)($om['revision_root_id']??0),(int)($om['revision_effective_child_id']??0),(int)($om['revision_pending_id']??0),(int)($om['revision_activated']['parent_id']??0)],true))$blocks[]='Başka bir revizyon bu kayda bağlı; tarihsel ilişki korunur.';
            }
        }
    }
    if($kind==='contract')foreach($schema['columns'] as $table=>$columns)if(in_array('contract_id',$columns,true)&&$read($table,'contract_id',$id))$blocks[]='Sözleşmeye bağlı ek kayıtlar var; otomatik zincirleme silme yapılmaz.';
    $delete[$entity]=['column'=>'id','value'=>$id];
    // Detect additional foreign keys, including references using nonstandard names.
    foreach($schema['foreign'] as $fk){
        $parent=$fk['REFERENCED_TABLE_NAME'];if(!isset($delete[$parent]))continue;
        $child=$fk['TABLE_NAME'];$col=$fk['COLUMN_NAME'];$ref=$fk['REFERENCED_COLUMN_NAME'];
        if($parent==='customers'&&$ref==='id'&&$child==='quotes'&&$col==='customer_id')continue;
        if($parent==='quotes'&&$ref==='id'&&$child==='v11_contracts'&&$col==='quote_id')continue;
        if($parent==='quotes'&&$ref==='id'&&$child==='ek_contract_share_links'&&$col==='quote_id'&&$children)continue;
        if($parent==='quotes'&&$ref==='id'&&$col==='quote_id'&&isset($delete[$child])&&$delete[$child]['column']==='quote_id')continue;
        $filter=$delete[$parent];$sql='SELECT COUNT(*) FROM '.ekDeleteIdentifier($child).' c JOIN '.ekDeleteIdentifier($parent).' p ON c.'.ekDeleteIdentifier($col).'=p.'.ekDeleteIdentifier($ref).' WHERE p.'.ekDeleteIdentifier($filter['column']).'=?';
        $s=$p->prepare($sql);$s->execute([$filter['value']]);if((int)$s->fetchColumn()>0)$blocks[]='Ek bir bağlı kayıt veya yabancı anahtar ilişkisi var; zincirleme silme engellendi.';
    }
    // All modified rows, including the minimal audit record, must roll back together.
    foreach(array_unique(array_merge(array_keys($delete),['v11_quote_events'])) as $table)if(strtoupper((string)($schema['tables'][$table]??''))!=='INNODB')$blocks[]='İşlemsel geri alma altyapısı doğrulanamadı; silme kapalı.';
    foreach($schema['foreign'] as $fk)if($fk['TABLE_NAME']==='v11_quote_events'&&$fk['COLUMN_NAME']==='quote_id'&&($kind==='quote'||$kind==='customer'))$blocks[]='Silme denetim kaydı mevcut tablo ilişkileriyle korunamıyor; silme kapalı.';
    $fingerprint=hash('sha256',serialize([$kind,$id,$record,$evidence,$schema,$blocks,array_column($children,'fingerprint')]));
    return ['kind'=>$kind,'id'=>$id,'quote_id'=>$quoteId,'title'=>$title,'fingerprint'=>$fingerprint,'blocks'=>array_values(array_unique($blocks)),'counts'=>$counts,'labels'=>$labels,'delete'=>$delete,'children'=>$children,'related'=>$related];
}
function ekLockQuoteOrReadForDelete(PDO $p,int $id,bool $lock):array {
    if($lock)return ekLockQuote($p,$id);$s=$p->prepare('SELECT * FROM quotes WHERE id=?');$s->execute([$id]);$q=$s->fetch(PDO::FETCH_ASSOC);if(!$q)throw new RuntimeException('Bağlı teklif bulunamadı.');return $q;
}
function ekPermanentlyDelete(PDO $p,string $kind,int $id,string $expected,string $action,int $actor):array {
    if($p->inTransaction()||$actor<1||!preg_match('/^[a-f0-9]{64}$/D',$expected))throw new RuntimeException('Silme isteği geçersiz.');
    $p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->beginTransaction();
    try{
        $s=$p->prepare('SELECT role FROM users WHERE id=?');$s->execute([$actor]);if(!in_array(strtolower((string)$s->fetchColumn()),['admin','administrator'],true))throw new RuntimeException('Kalıcı silme yalnız yöneticiye açıktır.');
        $plan=ekPermanentDeletePlan($p,$kind,$id,true);
        if($action!=='delete')throw new RuntimeException('Silme onayı eksik.');
        if(!hash_equals($plan['fingerprint'],$expected))throw new RuntimeException('Kayıt veya bağlı bilgiler değişmiş. Silme incelemesini yenileyin.');
        if($plan['blocks'])throw new RuntimeException(implode(' ',$plan['blocks']));
        ekExecuteDeletePlan($p,$plan,$actor);
        $p->commit();return $plan;
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}

// Called only with a freshly locked plan inside the single outer transaction.
function ekExecuteDeletePlan(PDO $p,array $plan,int $actor):void {
    foreach($plan['children'] as $child)ekExecuteDeletePlan($p,$child,$actor);
    $entity=['quote'=>'quotes','customer'=>'customers','contract'=>'v11_contracts'][$plan['kind']];$targets=$plan['delete'];$main=$targets[$entity];unset($targets[$entity]);$targets[$entity]=$main;
    foreach($targets as $table=>$filter){$s=$p->prepare('DELETE FROM '.ekDeleteIdentifier($table).' WHERE '.ekDeleteIdentifier($filter['column']).'=?');$s->execute([$filter['value']]);}
    $s=$p->prepare('INSERT INTO v11_quote_events(quote_id,event_type,actor_id,payload) VALUES(?,?,?,?)');$s->execute([$plan['quote_id'],'record_permanently_deleted',$actor,json_encode(['kind'=>$plan['kind'],'record_id'=>$plan['id'],'fingerprint'=>$plan['fingerprint'],'counts'=>$plan['counts']],JSON_THROW_ON_ERROR)]);
}
function ekCustomerArchiveCondition(string $alias='c'):string {
    // Latest state wins. Historical events contain IDs only, never customer details.
    return "COALESCE((SELECT JSON_EXTRACT(ca.payload,'$.archived') FROM v11_quote_events ca WHERE ca.quote_id=0 AND ca.event_type='customer_archive_state' AND JSON_EXTRACT(ca.payload,'$.customer_id')=".$alias.".id ORDER BY ca.id DESC LIMIT 1),0)";
}
function ekSetCustomerArchive(PDO $p,int $id,bool $archived,string $expected,int $actor):void {
    if($p->inTransaction()||$actor<1||!preg_match('/^[a-f0-9]{64}$/D',$expected))throw new RuntimeException('Geçersiz işlem.');
    $p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->beginTransaction();
    try{
        $s=$p->prepare('SELECT role FROM users WHERE id=?');$s->execute([$actor]);if(!in_array(strtolower((string)$s->fetchColumn()),['admin','administrator'],true))throw new RuntimeException('Arşivleme yalnız yöneticiye açıktır.');
        $plan=ekPermanentDeletePlan($p,'customer',$id,true);
        if(!hash_equals($plan['fingerprint'],$expected))throw new RuntimeException('Kayıt değişmiş. Onay ekranını yenileyin.');
        $schema=ekDeleteSchema($p);if(strtoupper($schema['tables']['v11_quote_events']??'')!=='INNODB')throw new RuntimeException('Arşiv altyapısı doğrulanamadı.');
        $s=$p->prepare("INSERT INTO v11_quote_events(quote_id,event_type,actor_id,payload) VALUES(0,'customer_archive_state',?,?)");$s->execute([$actor,json_encode(['customer_id'=>$id,'archived'=>$archived?1:0],JSON_THROW_ON_ERROR)]);
        $p->commit();
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
