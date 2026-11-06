<?php
function ekQuoteShareBlock(array $quote,array $meta,string $link,bool $archived=false):string {
 $shareStatus=(string)$quote['status'];
 $shareDeadline=(string)($quote['valid_until']??'');
 $isRevision=!empty($meta['revision_parent_id']);
 $revisionOffer=$meta['revision_offer']??[];
 return match(true){
 $archived=>'Teklif arşivde. Paylaşmak için önce geri yükleyin.',
 !empty($meta['revision_effective_child_id'])=>'Bu teklifin yerine yeni bir revizyon geçti.',
 $shareStatus==='expired'||(ekDate($shareDeadline)&&$shareDeadline<date('Y-m-d'))=>'Teklifin geçerlilik süresi doldu.',
 $isRevision&&($revisionOffer['state']??'')==='offered'&&($revisionOffer['expires_at']??'')<=date('Y-m-d H:i:s')=>'Revizyonun paylaşım süresi doldu.',
 $isRevision&&$link===''=>'Bu revizyon için etkin müşteri sunumu bulunmuyor.',
 !$isRevision&&$shareStatus==='draft'=>'Teklif taslak durumda. Paylaşmak için önce fiyatlandırın.',
 !$isRevision&&!in_array($shareStatus,['priced','sent'],true)=>'Teklif durumu “'.statusText($shareStatus).'”. Bu durumda yeni paylaşım yapılamaz.',
 !$isRevision&&!ekDate($shareDeadline)=>'Teklifin geçerlilik tarihi eksik veya geçersiz.',
 $link===''=>'Müşteri bağlantısı oluşturulamadı.',
 default=>'',
};
}

function ekQuoteShareArchived(PDO $pdo,int $id):bool {
 if(!ekTableExists($pdo,'quote_archives'))return false;
 $s=$pdo->prepare('SELECT 1 FROM quote_archives WHERE quote_id=?');
 $s->execute([$id]);
 return (bool)$s->fetchColumn();
}
