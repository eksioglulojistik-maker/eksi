(()=>{
 'use strict';
 const button=document.getElementById('ex-menu-button');
 const sidebar=document.getElementById('ex-sidebar');
 const overlay=document.getElementById('ex-backdrop');
 const shell=document.querySelector('.ex-shell');
 const closeButton=document.getElementById('ex-menu-close');
 if(!button||!sidebar||!overlay||!shell)return;
 const narrow=window.matchMedia('(max-width:850px)');
 const focusables=()=>Array.from(sidebar.querySelectorAll('a[href],button:not([disabled])')).filter(el=>el.getClientRects().length);
 function sync(){
  const open=narrow.matches&&document.body.classList.contains('ex-menu-open');
  sidebar.inert=narrow.matches&&!open;
  shell.inert=open;
  sidebar.setAttribute('aria-hidden',String(narrow.matches&&!open));
  button.setAttribute('aria-expanded',String(open));
  overlay.hidden=!open;
 }
 function close(restoreFocus=true){
  const wasOpen=document.body.classList.contains('ex-menu-open');
  document.body.classList.remove('ex-menu-open');sync();
  if(wasOpen&&restoreFocus)button.focus();
 }
 button.addEventListener('click',()=>{
  if(!narrow.matches)return;
  if(document.body.classList.contains('ex-menu-open')){close();return;}
  document.body.classList.add('ex-menu-open');sync();
  (closeButton||focusables()[0])?.focus();
 });
 overlay.addEventListener('click',()=>close());
 closeButton?.addEventListener('click',()=>close());
 document.addEventListener('keydown',event=>{
  if(!narrow.matches||!document.body.classList.contains('ex-menu-open'))return;
  if(event.key==='Escape'){event.preventDefault();close();return;}
  if(event.key!=='Tab')return;
  const items=focusables();if(!items.length)return;
  const index=items.indexOf(document.activeElement);
  if(event.shiftKey&&index<=0){event.preventDefault();items[items.length-1].focus();}
  else if(!event.shiftKey&&(index===items.length-1||index<0)){event.preventDefault();items[0].focus();}
 });
 narrow.addEventListener('change',()=>close(false));
 sync();
})();
