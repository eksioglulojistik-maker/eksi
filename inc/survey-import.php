<?php
require_once __DIR__.'/survey-operations.php';
/** Approved survey snapshot; read-only unless explicitly persisted inside quote save. */
function ekSurveyImport(PDO $p,int $id,int $actor,bool $lock=false):array {
 if($id<1||$actor<1)throw new RuntimeException('Geçerli bir ekspertiz seçin.');
 if($lock&&!$p->inTransaction())throw new RuntimeException('Aktarım işlem kilidi eksik.');
 if(!$p->inTransaction()){$p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->beginTransaction();try{$r=ekSurveyImport($p,$id,$actor);$p->commit();return $r;}catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}}
 $s=$p->prepare('SELECT role FROM users WHERE id=?');$s->execute([$actor]);$role=strtolower((string)$s->fetchColumn());if(!in_array($role,['admin','administrator','staff'],true))throw new RuntimeException('Aktarım yetkiniz yok.');
 $s=$p->prepare('SELECT id,customer_id,assigned_user_id,status,move_type,origin_address,destination_address,approved_volume FROM ek_surveys WHERE id=?'.($lock?' FOR UPDATE':''));$s->execute([$id]);$survey=$s->fetch(PDO::FETCH_ASSOC);
 if(!$survey||$survey['status']!=='approved')throw new RuntimeException('Aktarım için onaylı bir ekspertiz seçin.');
 if(!in_array($role,['admin','administrator'],true)&&(int)$survey['assigned_user_id']!==$actor)throw new RuntimeException('Bu ekspertize erişim yetkiniz yok.');
 $s=$p->prepare('SELECT id,room,item_name,quantity,unit_volume FROM ek_survey_items WHERE survey_id=? ORDER BY room,id'.($lock?' FOR UPDATE':''));$s->execute([$id]);$items=$s->fetchAll(PDO::FETCH_ASSOC);if(!$items)throw new RuntimeException('Ekspertizde aktarılacak eşya bulunamadı.');
 $sum=0;$pieces=0;$lines=[];
 foreach($items as $item){$qty=filter_var($item['quantity'],FILTER_VALIDATE_INT);$vol=is_numeric($item['unit_volume'])?(float)$item['unit_volume']:-1;
  if($qty===false||$qty<1||$qty>500||!is_finite($vol)||$vol<0||$vol>100||trim($item['room'])===''||trim($item['item_name'])===''||mb_strlen($item['room'])>100||mb_strlen($item['item_name'])>190)throw new RuntimeException('Ekspertiz eşya bilgileri geçersiz.');
  $sum+=$qty*$vol;$pieces+=$qty;$lines[]=$item['room'].' / '.$item['item_name'].' x'.$qty;
 }
 $volume=is_numeric($survey['approved_volume'])?(float)$survey['approved_volume']:-1;
 if(!is_finite($volume)||$volume<=0||$volume>100000||abs($sum-$volume)>0.00051)throw new RuntimeException('Onaylı hacim ile envanter toplamı uyuşmuyor. Ekspertiz kontrol edilmeli.');
 $site=svOpsReady($p)?svOpsMeta($p,$id)['fields']:[];
 $summary=implode("\n",$lines);$siteSummary=svOpsSummary($site);if($siteSummary!=='')$summary.="\n\nSAHA VE HİZMET BİLGİLERİ\n".$siteSummary;if(mb_strlen($summary)>12000)throw new RuntimeException('Envanter özeti teklif sınırını aşıyor; aktarım yapılmadı.');
 return ['survey'=>$survey,'items'=>$items,'site'=>$site,'volume'=>$volume,'pieces'=>$pieces,'summary'=>$summary,'fingerprint'=>hash('sha256',serialize([$survey,$items,$site]))];
}
function ekCopySurveyInventory(PDO $p,int $quoteId,array $source,int $customer,float $volume,string $expected):array {
 if(!$p->inTransaction()||!hash_equals($source['fingerprint'],$expected))throw new RuntimeException('Ekspertiz değişmiş. Aktarım formunu yeniden açın.');
 if($customer!==(int)$source['survey']['customer_id'])throw new RuntimeException('Aktarılan ekspertizin müşterisi değiştirilemez. Ayrı teklif için normal yeni teklif formunu kullanın.');
 if(abs($volume-$source['volume'])>0.00051)throw new RuntimeException('Aktarılan hacim onaylı ekspertizle uyuşmuyor.');
 $s=$p->prepare('SELECT customer_id FROM quotes WHERE id=? FOR UPDATE');$s->execute([$quoteId]);if((int)$s->fetchColumn()!==$customer)throw new RuntimeException('Teklif müşterisi doğrulanamadı.');
 $s=$p->prepare('SELECT COUNT(*) FROM v11_inventory WHERE quote_id=?');$s->execute([$quoteId]);if((int)$s->fetchColumn())throw new RuntimeException('Var olan teklif envanterinin üzerine yazılamaz.');
 $s=$p->prepare('INSERT INTO v11_inventory(quote_id,room,item_name,quantity,unit_volume,notes) VALUES(?,?,?,?,?,?)');
 foreach($source['items'] as $item)$s->execute([$quoteId,$item['room'],$item['item_name'],$item['quantity'],$item['unit_volume'],'Ekspertiz #'.(int)$source['survey']['id'].' aktarımı']);
 return ['survey_id'=>(int)$source['survey']['id'],'fingerprint'=>$source['fingerprint'],'item_count'=>count($source['items']),'pieces'=>$source['pieces'],'approved_volume'=>$source['volume'],'move_type'=>$source['survey']['move_type'],'site'=>$source['site']??[]];
}
