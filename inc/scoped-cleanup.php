<?php
// One-off, explicitly requested cleanup. Ordinary deletion protections stay unchanged.
require_once __DIR__.'/permanent-delete.php';
function ekCleanupPlan(PDO $p,bool $lock=false):array {
 if(!$p->inTransaction()){$p->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');$p->beginTransaction();try{$v=ekCleanupPlan($p);$p->commit();return $v;}catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}}
 $schema=ekDeleteSchema($p);$rows=[];$blocks=[];$targets=[];$keep=17;
 $known=['customers'=>'id','quotes'=>'customer_id','quote_details'=>'quote_id','moving_quote_meta'=>'quote_id','v11_inventory'=>'quote_id','v11_contracts'=>'quote_id','ek_contract_signatures'=>'quote_id','ek_contract_share_links'=>'quote_id','v11_quote_events'=>'quote_id','v11_quote_revisions'=>'quote_id','v11_moves'=>'quote_id','quote_archives'=>'quote_id','ek_quote_payments'=>'quote_id','ek_contract_otp'=>'quote_id','ek_notifications'=>'quote_id','v112_mail_logs'=>'quote_id'];
 $related=array_keys($known);foreach($schema['columns'] as $t=>$cols)if(array_intersect($cols,['customer_id','quote_id','contract_id','move_id','payment_id']))$related[]=$t;
 // Also inspect foreign keys whose names do not follow application conventions.
 do{$old=count($related);foreach($schema['foreign'] as $fk)if(in_array($fk['REFERENCED_TABLE_NAME'],$related,true))$related[]=$fk['TABLE_NAME'];$related=array_values(array_unique($related));}while(count($related)!==$old);
 sort($related);foreach($related as $t){if(!isset($schema['tables'][$t]))continue;
  if(strtoupper($schema['tables'][$t])!=='INNODB')$blocks[]='İşlemsel olmayan ilişkili tablo: '.$t;
  $v=$p->query('SELECT * FROM '.ekDeleteIdentifier($t).($lock?' FOR UPDATE':''))->fetchAll(PDO::FETCH_ASSOC);if(count($v)>20000)throw new RuntimeException('Kapsam güvenli işlem boyutunu aşıyor.');
  usort($v,fn($a,$b)=>strcmp(serialize($a),serialize($b)));$rows[$t]=$v;
 }
 foreach(['customers','quotes','v11_quote_events','ek_contract_signatures','ek_quote_payments','v11_contracts','v11_moves','moving_quote_meta'] as $t)if(!isset($rows[$t]))$blocks[]='Gerekli koruma tablosu eksik: '.$t;
 $kept=array_values(array_filter($rows['customers']??[],fn($c)=>(int)$c['id']===$keep));
 if(count($kept)!==1||mb_strtoupper(trim((string)($kept[0]['name']??'')),'UTF-8')!=='YUSUF YILDIZ')$blocks[]='Korunacak Yusuf Yıldız #17 doğrulanamadı.';
 $customerIds=array_map(fn($r)=>(int)$r['id'],array_values(array_filter($rows['customers']??[],fn($c)=>(int)$c['id']!==$keep)));
 $quoteIds=[];$keepQuotes=[];foreach($rows['quotes']??[] as $q){if(in_array((int)$q['customer_id'],$customerIds,true))$quoteIds[]=(int)$q['id'];elseif((int)$q['customer_id']===$keep)$keepQuotes[]=(int)$q['id'];else $blocks[]='Müşterisi doğrulanamayan teklif var: #'.(int)$q['id'];}
 $ids=['customer_id'=>$customerIds,'quote_id'=>$quoteIds];foreach(['v11_contracts'=>'contract_id','v11_moves'=>'move_id','ek_quote_payments'=>'payment_id'] as $t=>$col)$ids[$col]=array_map(fn($r)=>(int)$r['id'],array_values(array_filter($rows[$t]??[],fn($r)=>in_array((int)$r['quote_id'],$quoteIds,true))));
 foreach($rows as $t=>$data){foreach($data as $i=>$r){$delete=$t==='customers'?in_array((int)$r['id'],$customerIds,true):false;
   foreach($ids as $col=>$values)if(array_key_exists($col,$r)&&in_array((int)$r[$col],$values,true))$delete=true;
   if($t==='v11_quote_events'&&$r['event_type']==='scope_cleanup_completed')$blocks[]='Bu tek seferlik temizlik daha önce tamamlandı.';
   if($t==='v11_quote_events'&&$r['event_type']==='customer_archive_state'){$a=json_decode($r['payload']??'{}',true,512,JSON_THROW_ON_ERROR);if(in_array((int)($a['customer_id']??0),$customerIds,true))$delete=true;}
   if(!$delete)continue;
   if(!isset($known[$t])){$blocks[]='Bilinmeyen bağlı kayıt: '.$t;continue;}
   // A row linked to kept data must never be removed even if another column targets deletion.
   if(($t!=='customers'&&isset($r['customer_id'])&&(int)$r['customer_id']===$keep)||(isset($r['quote_id'])&&in_array((int)$r['quote_id'],$keepQuotes,true)))$blocks[]='Korunan kayıtla ortak ilişki var: '.$t;
   $targets[$t][]=$i;
  }}
 // Kept revision metadata must not lose a referenced quote.
 foreach($rows['moving_quote_meta']??[] as $r)if(in_array((int)$r['quote_id'],$keepQuotes,true)){$m=json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR);foreach(['revision_parent_id','revision_root_id','revision_effective_child_id','revision_pending_id'] as $key)if(in_array((int)($m[$key]??0),$quoteIds,true))$blocks[]='Yusuf Yıldız revizyon zinciri silinecek bir teklife bağlı.';if(in_array((int)($m['revision_activated']['parent_id']??0),$quoteIds,true))$blocks[]='Korunan revizyonun kaynak teklifi silinemez.';}
 // Validate every FK, including CASCADE, before deciding on deletion order.
 $edges=[];foreach($schema['foreign'] as $fk){$parent=$fk['REFERENCED_TABLE_NAME'];$child=$fk['TABLE_NAME'];if(empty($targets[$parent]))continue;
  $vals=[];foreach($targets[$parent] as $i)$vals[]=$rows[$parent][$i][$fk['REFERENCED_COLUMN_NAME']];
  foreach($rows[$child]??[] as $i=>$r)if($r[$fk['COLUMN_NAME']]!==null&&in_array($r[$fk['COLUMN_NAME']],$vals,true)&&!in_array($i,$targets[$child]??[],true))$blocks[]='Korunacak veya bilinmeyen yabancı anahtar bağımlılığı: '.$child;
  if(!empty($targets[$child])&&$child!==$parent)$edges[$parent][]=$child;
  if($child===$parent)$blocks[]='Öz ilişkili tablo için otomatik silme kapalı: '.$parent;
 }
 $triggers=$p->query('SELECT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);foreach($triggers as $t)if(isset($targets[$t])||$t==='v11_quote_events')$blocks[]='Silme yan etkisi doğrulanamayan tetikleyici: '.$t;
 $order=[];$pending=array_keys($targets);while($pending){$progress=false;foreach($pending as $k=>$t){if(array_intersect($edges[$t]??[],$pending))continue;$order[]=$t;unset($pending[$k]);$progress=true;}if(!$progress){$blocks[]='Tablo ilişkilerinde döngü var.';break;}}
 $counts=array_map('count',$targets);$protected=[];foreach($rows as $t=>$data){$protected[$t]=array_values(array_filter($data,fn($r,$i)=>!in_array($i,$targets[$t]??[],true),ARRAY_FILTER_USE_BOTH));}
 $fingerprint=hash('sha256',serialize([$schema,$rows,$targets,$blocks]));
 return compact('schema','rows','targets','counts','blocks','order','fingerprint','protected','customerIds','quoteIds','keepQuotes');
}
function ekCleanupBackup(PDO $p,array $plan):string {
 $dir=dirname(__DIR__,2).'/.eksioglu-cleanup-backups';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Yedek dizini oluşturulamadı.');
 $dir=realpath($dir);$root=realpath(dirname(__DIR__));if(!$dir||!$root||str_starts_with($dir,$root.DIRECTORY_SEPARATOR))throw new RuntimeException('Yedek dizini güvenli değil.');
 $name='before-cleanup-'.date('Ymd-His').'-'.bin2hex(random_bytes(6)).'.sql';$path=$dir.'/'.$name;$f=fopen($path,'xb');if(!$f)throw new RuntimeException('Yedek oluşturulamadı.');chmod($path,0600);
 $write=function($s)use($f){if(fwrite($f,$s)!==strlen($s))throw new RuntimeException('Yedek eksik yazıldı.');};
 try{$write("-- EKŞİOĞLU scoped cleanup backup\nSET FOREIGN_KEY_CHECKS=0;\n");
 foreach($plan['schema']['tables'] as $t=>$engine){$qt=ekDeleteIdentifier($t);$create=$p->query('SHOW CREATE TABLE '.$qt)->fetch(PDO::FETCH_NUM);$write('DROP TABLE IF EXISTS '.$qt.";\n".$create[1].";\n");
  $types=$p->prepare('SELECT COLUMN_NAME,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$types->execute([$t]);$types=$types->fetchAll(PDO::FETCH_KEY_PAIR);
  $data=$plan['rows'][$t]??$p->query('SELECT * FROM '.$qt)->fetchAll(PDO::FETCH_ASSOC);
  foreach($data as $r){$values=[];foreach($r as $c=>$v)$values[]=$v===null?'NULL':(preg_match('/blob|binary|bit/',strtolower($types[$c]))?"X'".bin2hex((string)$v)."'":"CONVERT(X'".bin2hex((string)$v)."' USING utf8mb4)");$write('INSERT INTO '.$qt.' ('.implode(',',array_map('ekDeleteIdentifier',array_keys($r))).') VALUES ('.implode(',',$values).");\n");}}
 $write("SET FOREIGN_KEY_CHECKS=1;\n");if(!fflush($f))throw new RuntimeException('Yedek diske yazılamadı.');fclose($f);$f=null;if(filesize($path)<100||!hash_file('sha256',$path))throw new RuntimeException('Yedek doğrulanamadı.');return $name;
 }catch(Throwable $e){if(is_resource($f))fclose($f);@unlink($path);throw $e;}
}
function ekExecuteCleanup(PDO $p,string $expected,int $actor):array {
 if($p->inTransaction()||!preg_match('/^[a-f0-9]{64}$/D',$expected))throw new RuntimeException('Geçersiz temizlik onayı.');
 $p->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');$p->beginTransaction();
 try{$s=$p->prepare('SELECT role FROM users WHERE id=?');$s->execute([$actor]);if(!in_array(strtolower((string)$s->fetchColumn()),['admin','administrator'],true))throw new RuntimeException('Yalnız yönetici.');
  $plan=ekCleanupPlan($p,true);if(!hash_equals($plan['fingerprint'],$expected))throw new RuntimeException('Kayıtlar değişmiş. Yeni önizleme ve onay gerekir.');if($plan['blocks'])throw new RuntimeException(implode(' ',$plan['blocks']));if(!$plan['customerIds'])throw new RuntimeException('Silinecek müşteri yok.');
  $backup=ekCleanupBackup($p,$plan);
  foreach($plan['order'] as $t){$key=in_array('id',$plan['schema']['columns'][$t],true)?'id':(in_array('quote_id',$plan['schema']['columns'][$t],true)?'quote_id':null);if(!$key)throw new RuntimeException('Güvenli satır anahtarı yok: '.$t);
   foreach($plan['targets'][$t] as $i){$s=$p->prepare('DELETE FROM '.ekDeleteIdentifier($t).' WHERE '.ekDeleteIdentifier($key).'=?');$s->execute([$plan['rows'][$t][$i][$key]]);if($s->rowCount()!==1)throw new RuntimeException('Silinecek satır değişmiş.');}}
  // Byte-for-byte verification of every non-target row in the inspected graph.
  foreach($plan['protected'] as $t=>$before){$after=$p->query('SELECT * FROM '.ekDeleteIdentifier($t))->fetchAll(PDO::FETCH_ASSOC);usort($after,fn($a,$b)=>strcmp(serialize($a),serialize($b)));if($after!==$before)throw new RuntimeException('Korunan kayıt doğrulaması başarısız: '.$t);}
  $s=$p->prepare("INSERT INTO v11_quote_events(quote_id,event_type,actor_id,payload) VALUES(0,'scope_cleanup_completed',?,?)");$s->execute([$actor,json_encode(['kept_customer_id'=>17,'counts'=>$plan['counts'],'fingerprint'=>$expected,'backup'=>$backup],JSON_THROW_ON_ERROR)]);
  $p->commit();return ['counts'=>$plan['counts'],'backup'=>$backup];
 }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
