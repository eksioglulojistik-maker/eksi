<?php
// Pure input/readiness helpers; no database connection or side effects.
function ekSurveyAppointment(string $value): ?string {
    $value=trim($value);
    if($value==='') return null;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value);
    if(!$date || $date->format('Y-m-d\TH:i')!==$value) {
        throw new RuntimeException('Randevu tarihi ve saati geçersiz.');
    }
    return $date->format('Y-m-d H:i:s');
}
function ekSurveyFilters(array $input): array {
    $scalar=static fn($key)=>is_scalar($input[$key]??null)?trim((string)$input[$key]):'';
    $status=$scalar('status');$type=$scalar('type');
    return ['q'=>mb_substr($scalar('q'),0,100),
        'status'=>in_array($status,['draft','assigned','approved','cancelled','completed'],true)?$status:'',
        'type'=>in_array($type,['domestic','international'],true)?$type:'',
        'page'=>max(1,min(100000,(int)$scalar('page')))];
}
function ekSurveyReadiness(array $survey,array $items): array {
    $issues=[];$volume=0;
    if(trim((string)($survey['origin_address']??''))==='')$issues[]='Çıkış adresini tamamlayın.';
    if(trim((string)($survey['destination_address']??''))==='')$issues[]='Varış adresini tamamlayın.';
    foreach($items as $item)$volume+=(int)$item['quantity']*(float)$item['unit_volume'];
    if($volume<=0)$issues[]='Pozitif hacimli eşya envanteri oluşturun.';
    return $issues;
}
