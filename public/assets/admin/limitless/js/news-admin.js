document.addEventListener('DOMContentLoaded', () => {
  const all = document.querySelector('#nn-select-all');
  const rows = [...document.querySelectorAll('.nn-row-check')];
  const count = document.querySelector('#nn-selected-count');
  const update = () => { const n=rows.filter(x=>x.checked).length; if(count) count.textContent=String(n); if(all){all.checked=n>0&&n===rows.length;all.indeterminate=n>0&&n<rows.length;} };
  all?.addEventListener('change',()=>{rows.forEach(x=>x.checked=all.checked);update();}); rows.forEach(x=>x.addEventListener('change',update));
  document.querySelectorAll('.nn-select-for-bulk').forEach(btn=>btn.addEventListener('click',()=>{const box=rows.find(x=>x.value===btn.dataset.id);if(box){box.checked=true;update();}btn.closest('details')?.removeAttribute('open');}));
  document.addEventListener('click',e=>document.querySelectorAll('.nn-more-actions[open]').forEach(d=>{if(!d.contains(e.target))d.removeAttribute('open');}));
  document.querySelectorAll('.nn-taxonomy-card').forEach(form=>{const name=form.querySelector('[name="name"]'), hidden=form.querySelector('.nn-auto-slug'), override=form.querySelector('.nn-slug-input'); const slug=v=>v.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,''); const sync=()=>hidden.value=slug(override?.value||name?.value||''); name?.addEventListener('input',sync);override?.addEventListener('input',sync);form.addEventListener('submit',sync);});
  document.querySelectorAll('#nn-news-filter-form select').forEach(el=>el.addEventListener('change',()=>document.querySelector('#nn-news-filter-form')?.submit()));
});
