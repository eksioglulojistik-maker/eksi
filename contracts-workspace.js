(() => {
  'use strict';
  const normalize = value => value.toLocaleLowerCase('tr').normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/ı/g, 'i');
  const search = document.getElementById('ec-search'), filter = document.getElementById('ec-status-filter');
  const files = [...document.querySelectorAll('.ec-quote')], empty = document.getElementById('ec-empty'), count = document.getElementById('ec-match-count');
  const filterFiles = () => {
    const term = normalize(search?.value.trim() || ''); let visible = 0;
    files.forEach(file => { file.hidden = !normalize(file.textContent).includes(term) || (filter?.value !== 'all' && filter?.value !== file.dataset.state); if (!file.hidden) visible++; });
    if (empty) empty.hidden = visible !== 0;
    if (count) count.textContent = visible + ' / ' + files.length + ' kayıt';
  };
  search?.addEventListener('input', filterFiles); filter?.addEventListener('change', filterFiles); filterFiles();
  const body = document.getElementById('ec-body'), lang = document.getElementById('ec-lang');
  if (!body || !lang) return;
  const counter = document.getElementById('ec-counter'), preview = document.getElementById('ec-preview-text'), unsaved = document.getElementById('ec-unsaved'), form = document.getElementById('ec-editor-form');
  const initialBody = body.value, initialLang = lang.value;
  let dirty = false, submitting = false;
  const refresh = () => {
    dirty = body.value !== initialBody || lang.value !== initialLang;
    if (counter) counter.textContent = body.value.length.toLocaleString('tr-TR') + ' / 30.000 karakter';
    if (preview) preview.textContent = body.value;
    if (unsaved) unsaved.textContent = dirty ? 'Kaydedilmemiş değişiklikler var.' : 'Ekrandaki metinde değişiklik yok.';
    document.querySelectorAll('.ec-share-primary button[type="submit"]').forEach(button => { button.disabled = dirty; button.title = dirty ? 'Önce sözleşme değişikliklerini kaydedin.' : ''; });
  };
  body.addEventListener('input', refresh);
  // Language identifies the document. Never replace a customer's saved text with a template.
  lang.addEventListener('change', refresh);
  form?.addEventListener('submit', () => { submitting = true; });
  window.addEventListener('beforeunload', event => { if (dirty && !submitting) { event.preventDefault(); event.returnValue = ''; } });
  const tabs = [...document.querySelectorAll('[data-ec-tab]')], tablist = document.querySelector('.ec-tabs');
  const activate = selected => {
    tabs.forEach(tab => { const active = tab === selected; tab.setAttribute('aria-selected', String(active)); tab.tabIndex = active ? 0 : -1; const panel = document.getElementById(tab.dataset.ecTab); if (panel) { panel.hidden = !active; panel.setAttribute('role', 'tabpanel'); } });
    if (selected.id === 'ec-tab-preview') refresh();
  };
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => activate(tab));
    tab.addEventListener('keydown', event => { let next; if (event.key === 'ArrowRight') next = (index + 1) % tabs.length; if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length; if (event.key === 'Home') next = 0; if (event.key === 'End') next = tabs.length - 1; if (next !== undefined) { event.preventDefault(); activate(tabs[next]); tabs[next].focus(); } });
  });
  if (tabs.length && tablist) { tablist.hidden = false; activate(tabs[0]); }
  refresh();
})();
