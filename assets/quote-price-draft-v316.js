(() => {
'use strict';
const $ = id => document.getElementById(id);
const rate=$('ek316-rate'), extra=$('ek316-extra'), discount=$('ek316-discount'), result=$('ek316-result'), apply=$('ek316-apply');
if(!rate||!extra||!discount||!result||!apply) return;
const value = el => el.matches('[data-ek-volume]')?window.ekVolumeNumber(el):Number(el.value);
function calculate() {
 const volume=$('mq-volume');
 const v=volume?value(volume):NaN, r=value(rate), e=value(extra), d=value(discount);
 if(!Number.isFinite(v)||v<=0||rate.value.trim()===''||![r,e,d].every(Number.isFinite)||[r,e,d].some(x=>x<0)||d>v*r+e) {
   result.textContent='Geçerli hacim, birim fiyat, ek bedel ve indirim girin.';return null;
 }
 const total=Math.round((v*r+e-d)*100)/100;
 if(!Number.isFinite(total)||total>999999999){result.textContent='Hesaplama sınırı aşıldı.';return null;}
 const currency=$('mq-currency')?.value||'EUR';
 result.textContent='Kontrol gerektiren fiyat taslağı: '+total.toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' '+currency;
 return {r,e,d};
}
[rate,extra,discount,$('mq-volume'),$('mq-currency')].filter(Boolean).forEach(el=>el.addEventListener('input',calculate));
apply.addEventListener('click',()=>{
 const v=calculate();if(!v)return;
 for(const [id,num] of [['mq-unit',v.r],['mq-extra',v.e],['mq-discount',v.d]]) {
  const el=$(id);if(!el)return;el.value=String(num);el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));
 }
 result.textContent+=' — Alanlara aktarıldı; personel kontrolü ve kaydetme gerekli.';
});
})();