<?php
require_once __DIR__.'/core.php';require_once __DIR__.'/record-protection.php';
function v11_admin(){auth();$s=db()->prepare('SELECT role FROM users WHERE id=?');$s->execute([$_SESSION['uid']]);if(!in_array(strtolower((string)$s->fetchColumn()),['admin','administrator'],true)){http_response_code(403);exit('Yönetici yetkisi gerekir.');}}
function v11_ready(){try{foreach(['v11_quote_events','v11_quote_revisions','v11_inventory','v11_moves','v11_contracts','moving_quote_meta'] as $table){$st=db()->query('SHOW TABLES LIKE '.db()->quote($table));if(!$st->fetchColumn())return false;}return true;}catch(Throwable $e){return false;}}
function v11_require(){if(!v11_ready()){http_response_code(503);require __DIR__.'/layout.php';top('V11 Kurulumu');echo '<div class="panel"><h2>V11 veritabanı kurulumu gerekiyor</h2><p>Yönetici hesabıyla <a href="system-check.php">Sistem Kontrolü</a> sayfasını açın.</p></div>';bottom();exit;}}
function v11_event($qid,$type,$data=[]){$p=db();$own=!$p->inTransaction();if($own)$p->beginTransaction();try{ekLockQuote($p,(int)$qid);$s=$p->prepare('INSERT INTO v11_quote_events(quote_id,event_type,actor_id,payload) VALUES (?,?,?,?)');$s->execute([$qid,$type,$_SESSION['uid']??null,json_encode($data,JSON_UNESCAPED_UNICODE)]);if($own)$p->commit();}catch(Throwable $e){if($own&&$p->inTransaction())$p->rollBack();throw $e;}}

function v11_meta($qid){$s=db()->prepare('SELECT payload FROM moving_quote_meta WHERE quote_id=?');$s->execute([$qid]);return json_decode($s->fetchColumn()?:'{}',true)?:[];}
function v11_quote($qid){$s=db()->prepare('SELECT q.*,c.name,c.email,c.phone,c.company FROM quotes q JOIN customers c ON c.id=q.customer_id WHERE q.id=?');$s->execute([$qid]);return $s->fetch();}
function v11_date($d){return $d?date('d.m.Y',strtotime($d)):'—';}
function v11_link($q){$rm=ekRevisionMeta(db(),(int)$q['id']);if(!empty($rm['revision_parent_id'])){try{return ekRevisionOfferLink(db(),(int)$q['id']);}catch(Throwable $e){return '';}} $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';$host=$_SERVER['HTTP_HOST']??'';if(!preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]+)?$/',$host))return '';return $scheme.'://'.$host.'/customer-quote.php?id='.$q['id'].'&token='.v11QuoteShareToken($q,$rm);}

// V11.1: Role checks for destructive actions; never trust a role stored only in the session.
function v111_is_admin(){
    if(empty($_SESSION['uid']))return false;
    $s=db()->prepare('SELECT role FROM users WHERE id=?');
    $s->execute([(int)$_SESSION['uid']]);
    return in_array(strtolower((string)$s->fetchColumn()),['admin','administrator'],true);
}

function v11QuotePicker(string $where='',bool $contracts=false):array {
 $term=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,120):'';
 $page=max(1,(int)($_GET['page']??1));
 $parameters=[];
 if($term!==''){
  $where.=($where===''?' WHERE ':' AND ').'(q.quote_no LIKE ? OR c.name LIKE ?)';
  $parameters=['%'.$term.'%','%'.$term.'%'];
 }
 $from=' FROM quotes q LEFT JOIN customers c ON c.id=q.customer_id'.$where;
 $s=db()->prepare('SELECT COUNT(*)'.$from);$s->execute($parameters);
 $count=(int)$s->fetchColumn();$last=max(1,(int)ceil($count/60));$page=min($page,$last);
 $fields=$contracts?', EXISTS (SELECT 1 FROM v11_contracts sc WHERE sc.quote_id=q.id) AS has_contract':'';
 $s=db()->prepare('SELECT q.id,q.quote_no,c.name'.$fields.$from.' ORDER BY q.id DESC LIMIT 60 OFFSET '.(($page-1)*60));
 $s->execute($parameters);
 return ['rows'=>$s->fetchAll(),'count'=>$count,'page'=>$page,'last'=>$last,'term'=>$term];
}
function v11QuotePickerNav(array $picker,int $id):void {
 $url=static fn(int $page):string=>'?'.http_build_query(['id'=>$id?:null,'q'=>$picker['term'],'page'=>$page]);
 ?>
 <nav aria-label="Teklif listesi sayfaları">
 <form method="get" role="search">
 <?php if($id):?><input type="hidden" name="id" value="<?=$id?>"><?php endif;?>
 <label>Müşteri veya teklif numarası <input type="search" name="q" value="<?=h($picker['term'])?>" maxlength="120"></label>
 <button type="submit">Ara</button>
 </form>
 <p><?=$picker['count']?> kayıt · <?=$picker['page']?> / <?=$picker['last']?> sayfa</p>
 <?php if($picker['page']>1):?><a href="<?=h($url($picker['page']-1))?>">Önceki</a><?php endif;?>
 <?php if($picker['page']<$picker['last']):?><a href="<?=h($url($picker['page']+1))?>">Sonraki</a><?php endif;?>
 </nav>
 <?php
}

function v11QuoteShareToken(array $quote,array $meta):string {
 $message='eksioglu-portal-v1|'.$quote['id'].'|'.$quote['quote_no'];
 if(!empty($meta['quote_share_nonce']))$message.='|'.$meta['quote_share_nonce'];
 return hash_hmac('sha256',$message,DB_PASS);
}
