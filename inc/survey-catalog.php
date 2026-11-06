<?php
function svCatalogRooms():array {
 return ['living'=>['Salon','🛋️'],'kitchen'=>['Mutfak','🍽️'],'bedroom'=>['Yatak Odası','🛏️'],'kids'=>['Çocuk Odası','🧸'],'office'=>['Çalışma Odası','🖥️'],'bath'=>['Banyo','🧺'],'hall'=>['Antre','🚪'],'balcony'=>['Balkon','🪴'],'storage'=>['Depo','📦'],'other'=>['Diğer','✨']];
}
function svCatalogItems():array {
 return [
  'sofa3'=>['Üçlü koltuk',2.1,'🛋️','sofa',['living']],
  'sofa2'=>['İkili koltuk',1.5,'🛋️','sofa',['living']],
  'sofaL'=>['L koltuk',3.2,'🛋️','sofa',['living']],
  'armchair'=>['Tekli koltuk',.9,'🪑','chair',['living','bedroom']],
  'coffee'=>['Orta sehpa',.35,'☕','table',['living']],
  'tv'=>['Televizyon',.25,'📺','tv',['living','bedroom']],
  'tvunit'=>['TV ünitesi',.8,'📺','cabinet',['living']],
  'dining'=>['Yemek masası',1.2,'🍽️','table',['living','kitchen']],
  'diningchair'=>['Yemek sandalyesi',.25,'🪑','chair',['living','kitchen']],
  'bookcase'=>['Kitaplık',1.2,'📚','shelf',['living','office','kids']],
  'rug'=>['Halı',.15,'🧶','rug',['living','bedroom','kids']],
  'fridge'=>['Buzdolabı',1.1,'🧊','fridge',['kitchen']],
  'dishwasher'=>['Bulaşık makinesi',.55,'🍽️','washer',['kitchen']],
  'oven'=>['Fırın',.5,'♨️','oven',['kitchen']],
  'freezer'=>['Derin dondurucu',.8,'❄️','fridge',['kitchen','storage']],
  'microwave'=>['Mikrodalga',.1,'♨️','oven',['kitchen']],
  'bed2'=>['Çift kişilik yatak',1.8,'🛏️','bed',['bedroom']],
  'bed1'=>['Tek kişilik yatak',1,'🛏️','bed',['bedroom','kids']],
  'wardrobe'=>['Gardırop',2.5,'🚪','wardrobe',['bedroom','kids']],
  'dresser'=>['Şifonyer',.7,'🗄️','cabinet',['bedroom','kids']],
  'nightstand'=>['Komodin',.25,'🛏️','cabinet',['bedroom']],
  'mirror'=>['Boy aynası',.15,'🪞','mirror',['bedroom','hall']],
  'crib'=>['Bebek yatağı',.7,'🍼','bed',['kids']],
  'toychest'=>['Oyuncak sandığı',.25,'🧸','box',['kids']],
  'desk'=>['Çalışma masası',.9,'✏️','table',['office','kids']],
  'officechair'=>['Ofis sandalyesi',.35,'🪑','chair',['office','kids']],
  'monitor'=>['Monitör',.08,'🖥️','tv',['office']],
  'printer'=>['Yazıcı',.12,'🖨️','oven',['office']],
  'filecabinet'=>['Dosya dolabı',.6,'🗄️','cabinet',['office']],
  'washer'=>['Çamaşır makinesi',.55,'🧺','washer',['bath','kitchen']],
  'dryer'=>['Kurutma makinesi',.55,'🧺','washer',['bath','storage']],
  'laundry'=>['Çamaşır sepeti',.1,'🧺','box',['bath']],
  'bathcabinet'=>['Banyo dolabı',.4,'🗄️','cabinet',['bath']],
  'shoerack'=>['Ayakkabılık',.5,'👟','cabinet',['hall']],
  'coatrack'=>['Portmanto',1.2,'🧥','wardrobe',['hall']],
  'bench'=>['Bank',.4,'🪑','table',['hall','balcony']],
  'gardenchair'=>['Balkon sandalyesi',.25,'🪑','chair',['balcony']],
  'gardentable'=>['Balkon masası',.4,'🪴','table',['balcony']],
  'plant'=>['Saksı / bitki',.15,'🪴','plant',['balcony','living']],
  'bike'=>['Bisiklet',.4,'🚲','bike',['storage','balcony']],
  'suitcase'=>['Bavul',.15,'🧳','box',['storage','bedroom']],
  'shelf'=>['Depo rafı',.8,'🗄️','shelf',['storage']],
  'boxSmall'=>['Küçük koli',.04,'📦','box',['living','kitchen','bedroom','kids','office','bath','hall','balcony','storage','other']],
  'boxMedium'=>['Orta koli',.08,'📦','box',['living','kitchen','bedroom','kids','office','bath','hall','balcony','storage','other']],
  'boxLarge'=>['Büyük koli',.12,'📦','box',['living','kitchen','bedroom','kids','office','bath','hall','balcony','storage','other']],
  'bag'=>['Eşya çantası',.1,'👜','box',['storage','other']],
 ];
}
function svCatalogRoomKey(string $room):string {
 foreach(svCatalogRooms() as $key=>$entry)if($entry[0]===$room)return $key;
 return 'other';
}
function svCatalogRoom(string $key):string {
 $rooms=svCatalogRooms();if(!isset($rooms[$key]))throw new RuntimeException('Geçerli bir oda seçin.');return $rooms[$key][0];
}
function svCatalogAdd(PDO $p,int $surveyId,string $key,string $roomKey):void {
 $room=svCatalogRoom($roomKey);$entry=svCatalogItems()[$key]??null;
 if(!$entry||!in_array($roomKey,$entry[4],true))throw new RuntimeException('Bu eşya seçilen odanın kataloğunda yok.');
 $s=$p->prepare("SELECT id,quantity FROM ek_survey_items WHERE survey_id=? AND room=? AND item_name=? AND unit_volume=? AND (notes='' OR notes IS NULL) ORDER BY id LIMIT 1 FOR UPDATE");$s->execute([$surveyId,$room,$entry[0],$entry[1]]);$row=$s->fetch(PDO::FETCH_ASSOC);
 if($row){if((int)$row['quantity']>=500)throw new RuntimeException('Bir eşya satırında en fazla 500 adet olabilir.');$s=$p->prepare('UPDATE ek_survey_items SET quantity=quantity+1 WHERE id=? AND survey_id=?');$s->execute([$row['id'],$surveyId]);}
 else{$s=$p->prepare("INSERT INTO ek_survey_items(survey_id,room,item_name,quantity,unit_volume,notes) VALUES(?,?,?,1,?,'')");$s->execute([$surveyId,$room,$entry[0],$entry[1]]);}
}
function svCatalogStep(PDO $p,int $surveyId,int $itemId,int $delta,bool $confirmRemove):void {
 if(!in_array($delta,[-1,1],true))throw new RuntimeException('Adet işlemi geçersiz.');
 $s=$p->prepare('SELECT quantity FROM ek_survey_items WHERE survey_id=? AND id=? FOR UPDATE');$s->execute([$surveyId,$itemId]);$qty=$s->fetchColumn();
 if($qty===false)throw new RuntimeException('Eşya kaydı bulunamadı.');$qty=(int)$qty;
 if($delta===1){if($qty>=500)throw new RuntimeException('En fazla 500 adet olabilir.');$s=$p->prepare('UPDATE ek_survey_items SET quantity=quantity+1 WHERE id=? AND survey_id=?');}
 elseif($qty>1)$s=$p->prepare('UPDATE ek_survey_items SET quantity=quantity-1 WHERE id=? AND survey_id=?');
 else{if(!$confirmRemove)throw new RuntimeException('Son eşyanın kaldırılması için onay gerekir.');$s=$p->prepare('DELETE FROM ek_survey_items WHERE id=? AND survey_id=?');}
 $s->execute([$itemId,$surveyId]);
}
function svCatalogSnapshot(PDO $p,int $surveyId):array {
 $s=$p->prepare('SELECT id,room,item_name,quantity,unit_volume,notes FROM ek_survey_items WHERE survey_id=? ORDER BY room,id');$s->execute([$surveyId]);$items=$s->fetchAll(PDO::FETCH_ASSOC);$total=0;$pieces=0;$rooms=[];
 foreach($items as &$item){$item['id']=(int)$item['id'];$item['quantity']=(int)$item['quantity'];$item['unit_volume']=(float)$item['unit_volume'];$item['room_key']=svCatalogRoomKey($item['room']);$v=$item['quantity']*$item['unit_volume'];$total+=$v;$pieces+=$item['quantity'];$key=$item['room_key'];if(!isset($rooms[$key]))$rooms[$key]=['pieces'=>0,'volume'=>0];$rooms[$key]['pieces']+=$item['quantity'];$rooms[$key]['volume']+=$v;}
 unset($item);return ['revision'=>hash('sha256',json_encode($items,JSON_THROW_ON_ERROR)),'items'=>$items,'volume'=>round($total,3),'pieces'=>$pieces,'rooms'=>$rooms];
}
function svCatalogReceipt(array $post):array {
 $key=$post['request_id']??'';if(!is_string($key)||!preg_match('/^[a-f0-9]{32}$/D',$key))throw new RuntimeException('İşlem kimliği geçersiz. Sayfayı yenileyin.');
 $fields=[];foreach(['action','id','catalog_key','room_key','item_id','delta','confirm_remove','confirm_clear','revision'] as $name)$fields[$name]=is_scalar($post[$name]??null)?(string)$post[$name]:'';
 return [$key,hash('sha256',json_encode($fields,JSON_THROW_ON_ERROR))];
}

// Caller holds the survey row lock and checks permissions/status before entering.
function svCatalogClear(PDO $p,int $surveyId,string $revision,bool $confirmed):void {
 if(!$confirmed)throw new RuntimeException('Envanteri temizleme onayı gerekli.');
 $current=svCatalogSnapshot($p,$surveyId);
 if(!preg_match('/^[a-f0-9]{64}$/D',$revision)||!hash_equals($current['revision'],$revision))throw new RuntimeException('Envanter başka bir işlemle değişti. Sayfayı yenileyip tekrar kontrol edin.');
 $q=$p->prepare('DELETE FROM ek_survey_items WHERE survey_id=?');$q->execute([$surveyId]);
}
