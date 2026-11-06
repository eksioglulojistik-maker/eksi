<?php
require_once __DIR__.'/record-protection.php';
// Call inside an active transaction after locking the quote row.
function ekAcceptanceDocuments($pdo,$q,$source,$date=null){
 if(!$pdo->inTransaction())throw new LogicException('Kabul işlemi transaction gerektirir.');
 if($date!==null&&!ekDate($date))throw new RuntimeException('Yükleme tarihi geçersiz.');
 $s=$pdo->prepare('SELECT payload FROM moving_quote_meta WHERE quote_id=?');$s->execute([$q['id']]);$meta=json_decode($s->fetchColumn()?:'{}',true)?:[];
 if(!empty($meta['revision_effective_child_id']))throw new RuntimeException('Tarihsel kaynak için yeni işlem oluşturulamaz. Geçerli revizyonun taşınma dosyasını açın.');
 if((int)($meta['revision_parent_id']??0)>0&&($source!=='revision_customer'||empty($meta['revision_activated'])))throw new RuntimeException('Revizyon taslağı otomatik kabul edilemez. Önce eski sözleşme ve tahsilatlarla revizyon mutabakatı gerekir.');
 if($date===null&&!empty($meta['move_date'])&&ekDate($meta['move_date']))$date=$meta['move_date'];
 if($source==='revision_customer')$date=null; // Retain the existing physical move; do not duplicate the operation.
 $body="ULUSLARARASI EV EŞYASI TAŞIMA SÖZLEŞMESİ — TASLAK\n\nTeklif: ".$q['quote_no']."\nMüşteri: ".$q['name']."\nGüzergâh: ".$q['origin'].' → '.$q['destination']."\nHacim: " .number_format((float)$q['volume'],2,',','.')." m³\nBedel: ".number_format((float)$q['total'],2,',','.').' '.$q['currency']."\n\nBu belge kabul edilen teklife bağlı, imzalanmamış sözleşme taslağıdır. Ayrıntılar taraflarca tamamlanmalıdır.";
 $s=$pdo->prepare('SELECT * FROM quote_details WHERE quote_id=?');$s->execute([$q['id']]);$detail=$s->fetch()?:[];
 foreach(['service_scope'=>'Hizmetlerimiz','payment_terms'=>'Ödeme Şekli','delivery_time'=>'Teslimat Süresi','additional_terms'=>'Diğer Hususlar'] as $key=>$label){$text=html_entity_decode(strip_tags(str_replace(['<br>','<br/>','<br />','</p>','</li>'],"\n",ekQuoteSections($detail,$q)[$key])),ENT_QUOTES,'UTF-8');if(trim($text)!=='')$body.="\n\n".$label.":\n".$text;}
 $s=$pdo->prepare("INSERT INTO v11_contracts (quote_id,language,body) VALUES (?,'tr',?) ON DUPLICATE KEY UPDATE quote_id=VALUES(quote_id)");$s->execute([$q['id'],$body]);
 if($date!==null){$s=$pdo->prepare("INSERT INTO v11_moves (quote_id,loading_date,status,notes) VALUES (?,?,'planned',?) ON DUPLICATE KEY UPDATE quote_id=VALUES(quote_id)");$s->execute([$q['id'],$date,'Teklif kabulünden oluşturuldu; operasyon ayrıntılarını kontrol edin.']);}
 $s=$pdo->prepare('INSERT INTO v11_quote_events (quote_id,event_type,actor_id,payload) VALUES (?,?,?,?)');$s->execute([$q['id'],'quote_acceptance_workflow',($source==='customer'?null:($_SESSION['uid']??null)),json_encode(['source'=>$source,'loading_date'=>$date,'schedule_pending'=>$date===null],JSON_UNESCAPED_UNICODE)]);
 return $date!==null;
}
