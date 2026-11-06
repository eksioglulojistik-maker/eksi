<?php
// Additive survey workspace. Schema installation is an explicit administrator POST.
function svOpsReady(PDO $p): bool {
    foreach(['ek_survey_workspace','ek_survey_activity'] as $name){$s=$p->query('SHOW TABLES LIKE '.$p->quote($name));if(!$s->fetchColumn())return false;}
    return true;
}
function svOpsInstall(PDO $p): void {
    $p->exec("CREATE TABLE IF NOT EXISTS ek_survey_workspace (survey_id INT UNSIGNED PRIMARY KEY,parent_id INT UNSIGNED NULL,payload MEDIUMTEXT NOT NULL,created_by INT NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_parent(parent_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $p->exec("CREATE TABLE IF NOT EXISTS ek_survey_activity (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,survey_id INT UNSIGNED NOT NULL,actor_id INT NOT NULL,event_type VARCHAR(40) NOT NULL,detail TEXT NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_survey(survey_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function svOpsMeta(PDO $p,int $id): array {
    $s=$p->prepare('SELECT parent_id,payload FROM ek_survey_workspace WHERE survey_id=?');$s->execute([$id]);$r=$s->fetch(PDO::FETCH_ASSOC);
    if(!$r)return ['parent_id'=>null,'fields'=>[]];
    return ['parent_id'=>$r['parent_id'],'fields'=>json_decode($r['payload'],true,32,JSON_THROW_ON_ERROR)];
}
function svOpsFields(array $input): array {
    $out=[];
    foreach(['origin','destination'] as $side){
        $floor=$input[$side.'_floor']??'';
        if(!is_scalar($floor)||($floor!==''&&(!preg_match('/^-?\d{1,2}$/D',(string)$floor)||(int)$floor < -5||(int)$floor>99)))throw new RuntimeException('Kat bilgisi -5 ile 99 arasında olmalıdır.');
        $out[$side.'_floor']=$floor===''?'':(string)(int)$floor;
        $distance=$input[$side.'_distance']??'';
        if(!is_scalar($distance)||($distance!==''&&(!is_numeric($distance)||(float)$distance<0||(float)$distance>2000)))throw new RuntimeException('Taşıma mesafesi 0–2000 metre arasında olmalıdır.');
        $out[$side.'_distance']=$distance===''?'':(string)(float)$distance;
        foreach(['elevator'=>['unknown','yes','no'],'truck'=>['unknown','yes','no'],'lift'=>['unknown','yes','no']] as $field=>$allowed){$v=$input[$side.'_'.$field]??'unknown';if(!is_string($v)||!in_array($v,$allowed,true))throw new RuntimeException('Erişim seçimi geçersiz.');$out[$side.'_'.$field]=$v;}
    }
    foreach(['packing','assembly','storage'] as $field){$v=$input[$field]??'unknown';if(!is_string($v)||!in_array($v,['unknown','yes','no'],true))throw new RuntimeException('Hizmet seçimi geçersiz.');$out[$field]=$v;}
    foreach(['special_items','site_notes'] as $field){$v=$input[$field]??'';if(!is_string($v)||mb_strlen($v)>2000)throw new RuntimeException('Saha notu en fazla 2000 karakter olabilir.');$out[$field]=trim($v);}
    return $out;
}
function svOpsEvent(PDO $p,int $id,int $actor,string $event,string $detail=''):void {
    $s=$p->prepare('INSERT INTO ek_survey_activity(survey_id,actor_id,event_type,detail) VALUES(?,?,?,?)');$s->execute([$id,$actor,$event,mb_substr($detail,0,2000)]);
}
function svOpsSave(PDO $p,int $id,int $actor,array $fields):void {
    $s=$p->prepare('SELECT survey_id FROM ek_survey_workspace WHERE survey_id=?');$s->execute([$id]);$exists=$s->fetchColumn();
    if($exists){$s=$p->prepare('UPDATE ek_survey_workspace SET payload=? WHERE survey_id=?');$s->execute([json_encode($fields,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id]);}
    else{$s=$p->prepare('INSERT INTO ek_survey_workspace(survey_id,payload,created_by) VALUES(?,?,?)');$s->execute([$id,json_encode($fields,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$actor]);}
}
function svOpsVolume(array $input): ?float {
    $raw=[$input['length_cm']??'',$input['width_cm']??'',$input['height_cm']??''];
    if(count(array_filter($raw,fn($v)=>$v!==''))===0)return null;
    foreach($raw as $value)if(!is_scalar($value)||!is_numeric($value)||!is_finite((float)$value)||(float)$value<=0||(float)$value>1000)throw new RuntimeException('Üç ölçüyü de 0–1000 cm arasında girin.');
    $volume=round((float)$raw[0]*(float)$raw[1]*(float)$raw[2]/1000000,3);
    if($volume<=0||$volume>100)throw new RuntimeException('Ölçülerden hesaplanan hacim 0–100 m³ arasında olmalıdır.');
    return $volume;
}
function svOpsMeasurementNote(array $input):string {
    if(svOpsVolume($input)===null)return '';
    return 'Ölçü (en × boy × yükseklik): '.(float)$input['width_cm'].' × '.(float)$input['length_cm'].' × '.(float)$input['height_cm'].' cm. Tahmini geometrik hacim.';
}
function svOpsRevise(PDO $p,array $source,int $actor,string $reason): int {
    if(!$p->inTransaction())throw new LogicException('Revizyon işlem kilidi gerektirir.');
    if($source['status']!=='approved'||trim($reason)===''||mb_strlen($reason)>1000)throw new RuntimeException('Onaylı kayıt seçin ve revizyon gerekçesini yazın (en fazla 1000 karakter).');
    $s=$p->prepare("SELECT w.survey_id FROM ek_survey_workspace w JOIN ek_surveys s ON s.id=w.survey_id WHERE w.parent_id=? AND s.status IN ('draft','assigned')");$s->execute([$source['id']]);if($s->fetchColumn())throw new RuntimeException('Bu kaydın açık bir revizyonu var. Önce onu tamamlayın.');
    $s=$p->prepare("INSERT INTO ek_surveys(customer_id,assigned_user_id,move_type,origin_address,destination_address,appointment_at,created_by,status) VALUES(?,?,?,?,?,?,?,'draft')");$s->execute([$source['customer_id'],$source['assigned_user_id'],$source['move_type'],$source['origin_address'],$source['destination_address'],$source['appointment_at'],$actor]);$new=(int)$p->lastInsertId();
    $s=$p->prepare('INSERT INTO ek_survey_items(survey_id,room,item_name,quantity,unit_volume,notes) SELECT ?,room,item_name,quantity,unit_volume,notes FROM ek_survey_items WHERE survey_id=?');$s->execute([$new,$source['id']]);
    $meta=svOpsMeta($p,(int)$source['id']);
    $s=$p->prepare('INSERT INTO ek_survey_workspace(survey_id,parent_id,payload,created_by) VALUES(?,?,?,?)');$s->execute([$new,$source['id'],json_encode($meta['fields'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$actor]);
    svOpsEvent($p,$new,$actor,'revision_created',$reason);svOpsEvent($p,(int)$source['id'],$actor,'revision_requested','Yeni ekspertiz #'.$new.' · '.$reason);
    // Photos remain with the original approved record; no shared file ownership or unlink risk.
    return $new;
}
function svOpsQuotes(PDO $p,int $id):array {
    $s=$p->prepare("SELECT q.id,q.quote_no FROM quotes q JOIN moving_quote_meta mm ON mm.quote_id=q.id WHERE JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(mm.payload) THEN mm.payload ELSE '{}' END,'$.survey_import.survey_id'))=? ORDER BY q.id DESC LIMIT 30");$s->execute([(string)$id]);return $s->fetchAll(PDO::FETCH_ASSOC);
}
function svOpsQuoteMap(PDO $p,array $ids):array {
    if(!$ids)return [];$ids=array_map('intval',$ids);$slots=implode(',',array_fill(0,count($ids),'?'));
    $expression="JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(mm.payload) THEN mm.payload ELSE '{}' END,'$.survey_import.survey_id'))";
    $s=$p->prepare('SELECT q.id,q.quote_no,'.$expression.' survey_id FROM quotes q JOIN moving_quote_meta mm ON mm.quote_id=q.id WHERE '.$expression.' IN ('.$slots.') ORDER BY q.id DESC');$s->execute($ids);$map=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $q)$map[(int)$q['survey_id']][]=$q;
    return $map;
}
function svOpsAssignee(PDO $p,int $id):string {
    if(!$id)return 'Atanmadı';$s=$p->prepare('SELECT name FROM users WHERE id=?');$s->execute([$id]);return (string)($s->fetchColumn()?:'Personel');
}
function svOpsMissing(array $fields):array {
    $issues=[];
    foreach(['origin'=>'Çıkış','destination'=>'Varış'] as $side=>$label){
        if(($fields[$side.'_floor']??'')==='')$issues[]=$label.' kat bilgisini tamamlayın.';
        if(($fields[$side.'_distance']??'')==='')$issues[]=$label.' taşıma mesafesini tamamlayın.';
        foreach(['elevator'=>'asansör','truck'=>'araç yanaşma'] as $key=>$name)if(($fields[$side.'_'.$key]??'unknown')==='unknown')$issues[]=$label.' '.$name.' durumunu belirleyin.';
    }
    foreach(['packing'=>'Paketleme','assembly'=>'Söküm / kurulum'] as $key=>$label)if(($fields[$key]??'unknown')==='unknown')$issues[]=$label.' ihtiyacını belirleyin.';
    return $issues;
}
function svOpsEventLabel(string $event):string {
    return ['revision_created'=>'Revizyon oluşturuldu','revision_requested'=>'Revizyon açıldı','status_changed'=>'Durum değişti','brief'=>'Saha bilgileri kaydedildi','item'=>'Eşya eklendi','update_item'=>'Eşya güncellendi','remove'=>'Eşya kaldırıldı','photo'=>'Fotoğraf yüklendi','delete_photo'=>'Fotoğraf kaldırıldı','status'=>'Ekspertiz onaylandı'][$event]??'Dosya işlemi';
}
function svOpsLabels():array {
    return ['origin_floor'=>'Çıkış · Kat','origin_elevator'=>'Çıkış · Bina asansörü','origin_distance'=>'Çıkış · Elde taşıma mesafesi (m)','origin_truck'=>'Çıkış · Araç yanaşabilir','origin_lift'=>'Çıkış · Dış cephe asansörü gerekli','destination_floor'=>'Varış · Kat','destination_elevator'=>'Varış · Bina asansörü','destination_distance'=>'Varış · Elde taşıma mesafesi (m)','destination_truck'=>'Varış · Araç yanaşabilir','destination_lift'=>'Varış · Dış cephe asansörü gerekli','packing'=>'Paketleme gerekli','assembly'=>'Söküm / kurulum gerekli','storage'=>'Depolama gerekli','special_items'=>'Özel / ağır / kırılabilir eşyalar','site_notes'=>'Erişim, gümrük ve diğer saha notları'];
}
function svOpsRenderFields(array $fields,bool $readonly):void {
    echo '<div class="sv-site-fields"><h3>Erişim ve hizmet ihtiyaçları</h3><p>Çıkış ve varış koşullarını ayrı ayrı kontrol edin. Bilinmeyen bilgileri tahmin ederek doldurmayın.</p><div class="sv-grid">';
    foreach(svOpsLabels() as $key=>$label){$value=$fields[$key]??'';
        if($readonly){$display=in_array($key,['special_items','site_notes'],true)||str_ends_with($key,'_floor')||str_ends_with($key,'_distance')?($value===''?'Belirtilmedi':$value):(['yes'=>'Evet','no'=>'Hayır','unknown'=>'Belirlenmedi'][$value]??'Belirlenmedi');echo '<div class="sv-site-value"><small>'.h($label).'</small><p>'.nl2br(h($display)).'</p></div>';continue;}
        echo '<label>'.h($label);
        if(in_array($key,['special_items','site_notes'],true))echo '<textarea name="'.h($key).'" rows="2" maxlength="2000">'.h($value).'</textarea>';
        elseif(str_ends_with($key,'_floor')||str_ends_with($key,'_distance'))echo '<input type="number" name="'.h($key).'" value="'.h($value).'" min="'.(str_ends_with($key,'_floor')?'-5':'0').'" max="'.(str_ends_with($key,'_floor')?'99':'2000').'" step="'.(str_ends_with($key,'_floor')?'1':'0.1').'">';
        else{echo '<select name="'.h($key).'">';foreach(['unknown'=>'Belirlenmedi','yes'=>'Evet','no'=>'Hayır'] as $v=>$text)echo '<option value="'.$v.'" '.($v===($value?:'unknown')?'selected':'').'>'.$text.'</option>';echo '</select>';}
        echo '</label>';
    }
    echo '</div></div>';
}
function svOpsSummary(array $fields):string {
    $lines=[];foreach(svOpsLabels() as $key=>$label){$value=$fields[$key]??'';if($value===''||$value==='unknown')continue;$lines[]=$label.': '.(['yes'=>'Evet','no'=>'Hayır'][$value]??$value);}
    return implode("\n",$lines);
}
