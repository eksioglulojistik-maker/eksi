<?php
require __DIR__.'/inc/core.php';auth();if(!ekAdmin()){http_response_code(403);exit('Yönetici yetkisi gerekir.');}
$catalogTable='email_templates';$catalogPath='emails.php';$catalogTitle='E-posta Şablonları';$catalogDescription='Gönderim ekranında seçilebilir. Değişkenler: {customer_name}, {quote_number}, {quote_link}, {quote_content}. Bağlantı bu değişkenlerle aktarılır.';
$catalogFields=[
'name'=>['label'=>'Şablon adı','type'=>'text','required'=>true],
'language'=>['label'=>'Dil','type'=>'select','required'=>true,'options'=>['TR'=>'Türkçe','EN'=>'English'],'default'=>'TR'],
'subject'=>['label'=>'E-posta konusu','type'=>'text','required'=>true,'max'=>180],
'body'=>['label'=>'Mesaj','type'=>'textarea','required'=>true,'max'=>8000]
];require __DIR__.'/inc/catalog-page.php';
