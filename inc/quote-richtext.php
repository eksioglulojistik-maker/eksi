<?php
// Controlled rich text for quotation sections. Plain-text legacy records remain readable.
function ekRichDecode($value) {
  $value=(string)$value;
  if(strncmp($value,'EKRICH64:',9)!==0)return $value;
  $encoded=substr($value,9);
  if(strlen($encoded)>100000 || !preg_match('/^[A-Za-z0-9+\/=]*$/D',$encoded))throw new Exception('Geçersiz metin biçimi.');
  $decoded=base64_decode($encoded,true);
  if($decoded===false || !preg_match('//u',$decoded))throw new Exception('Geçersiz metin kodlaması.');
  return $decoded;
}
function ekRichClean($value) {
  $value=ekRichDecode($value);
  $value=trim((string)$value);
  if($value==='')return '';
  if(!preg_match('/<[^>]+>/', $value))return $value;
  if(!class_exists('DOMDocument'))throw new RuntimeException('Biçimli metin için PHP DOM eklentisi gerekir.');
  $doc=new DOMDocument('1.0','UTF-8');
  $previous=libxml_use_internal_errors(true);
  $doc->loadHTML('<?xml encoding="utf-8"?><div id="ek-rich-root">'.$value.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD|LIBXML_NONET);
  libxml_clear_errors();libxml_use_internal_errors($previous);
  $root=$doc->getElementById('ek-rich-root');if(!$root)return htmlspecialchars(strip_tags($value),ENT_QUOTES,'UTF-8');
  $allowed=['p','div','br','strong','b','em','i','u','s','span','ul','ol','li'];
  $walk=function($node)use(&$walk,$allowed,$doc){
    foreach(iterator_to_array($node->childNodes) as $child){
      if($child->nodeType===XML_COMMENT_NODE){$node->removeChild($child);continue;}
      if($child->nodeType!==XML_ELEMENT_NODE)continue;
      $tag=strtolower($child->nodeName);
      if(in_array($tag,['script','style','iframe','object','svg','math','form','img','video','audio'],true)){$node->removeChild($child);continue;}
      if(!in_array($tag,$allowed,true)){
        $walk($child);
        while($child->firstChild)$node->insertBefore($child->firstChild,$child);
        $node->removeChild($child);continue;
      }
      $style=$child->getAttribute('style');
      if($tag==='font'){$style.=';color:'.$child->getAttribute('color');}
      $style=preg_replace_callback('/rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/i',function($m){return sprintf('#%02x%02x%02x',min(255,(int)$m[1]),min(255,(int)$m[2]),min(255,(int)$m[3]));},$style);
      while($child->attributes->length)$child->removeAttributeNode($child->attributes->item(0));
      if($style!==''){
        $safe=[];
        if(preg_match('/(?:^|;)\s*color\s*:\s*(#[0-9a-f]{6}|#[0-9a-f]{3})\s*(?:!important\s*)?(?:;|$)/i',$style,$m))$safe[]='color:'.$m[1];
        if(preg_match('/(?:^|;)\s*font-size\s*:\s*(1[0-9]|2[0-4])px\s*(?:!important\s*)?(?:;|$)/i',$style,$m))$safe[]='font-size:'.$m[1].'px';
        if(preg_match('/(?:^|;)\s*font-weight\s*:\s*(bold|[6-9]00)\s*(?:;|$)/i',$style))$safe[]='font-weight:700';
        if(preg_match('/(?:^|;)\s*font-style\s*:\s*italic\s*(?:;|$)/i',$style))$safe[]='font-style:italic';
        if(preg_match('/(?:^|;)\s*text-decoration(?:-line)?\s*:\s*underline\s*(?:;|$)/i',$style))$safe[]='text-decoration:underline';
        if($safe)$child->setAttribute('style',implode(' !important;',$safe).' !important');
      }
      $walk($child);
    }
  };
  $walk($root);$out='';foreach($root->childNodes as $child)$out.=$doc->saveHTML($child);
  return $out;
}
function ekRichDisplay($value){
  $clean=ekRichClean($value);
  if(!preg_match('/<\/?(?:p|div|br|strong|b|em|i|u|s|span|ul|ol|li)\b/i',$clean))return nl2br(htmlspecialchars($clean,ENT_QUOTES,'UTF-8'));
  return $clean;
}

function ekQuoteSections($detail,$quote=[]){
 $out=[];
 foreach(['service_scope','payment_terms','delivery_time','additional_terms'] as $key)$out[$key]=ekRichDisplay($detail[$key]??'');
 if(trim((string)($detail['transport_process']??''))!=='')$out['service_scope'].='<div>'.ekRichDisplay($detail['transport_process']).'</div>';
 foreach(['terms_snapshot','notes'] as $key)if(trim((string)($quote[$key]??''))!=='')$out['additional_terms'].='<p>'.nl2br(htmlspecialchars($quote[$key],ENT_QUOTES,'UTF-8')).'</p>';
 return $out;
}

function ekRichEditor($value){try{return ekRichClean($value);}catch(Throwable $e){return '';}}
