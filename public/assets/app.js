'use strict';
const base = document.body.dataset.base || '';
const menu = document.getElementById('menu-toggle');
const sidebar = document.getElementById('sidebar');
const backdrop = document.getElementById('nav-backdrop');
function closeMenu() { sidebar?.classList.remove('open'); menu?.setAttribute('aria-expanded','false'); if(backdrop) backdrop.hidden=true; }
menu?.addEventListener('click',()=>{const open=sidebar.classList.toggle('open');menu.setAttribute('aria-expanded',String(open));backdrop.hidden=!open;});
backdrop?.addEventListener('click',closeMenu);
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeMenu();});
const form=document.getElementById('announcement-form');
if(form){
 const title=document.getElementById('title'); const previewTitle=document.getElementById('preview-title'); const body=document.getElementById('body'); let edited=!!form.dataset.editing;
 const render=()=>{previewTitle.textContent=title.value||'Your announcement title';if(body)document.getElementById('preview-body').textContent=body.value||'Your announcement preview will appear here.';};
 const detect=()=>{if(!body.value.trim()){render();return;} const original=body.value.split(/\r\n|\n|\r/).find(line=>/^\s*(?:[\u{1F000}-\u{1FAFF}\u2600-\u27BF]\s*)*(?:Power\s+(?:Service\s+)?(?:Advisory|Interruption)|Partial\s+Power\s+Interruption)/iu.test(line)); const now=new Date();title.value=original??`Partial Power Interruption Update ${new Intl.DateTimeFormat('en-US',{timeZone:'Asia/Manila',month:'2-digit',day:'2-digit',year:'2-digit'}).format(now)} ${new Intl.DateTimeFormat('en-US',{timeZone:'Asia/Manila',hour:'numeric',minute:'2-digit'}).format(now)}`;render();};
 title.addEventListener('input',()=>{edited=true;render();});
 body?.addEventListener('input',()=>{if(!edited)detect();else render();});document.getElementById('detect-title')?.addEventListener('click',()=>{edited=false;detect();});
 const date=document.getElementById('date');date?.addEventListener('change',()=>{if(date.value&&!edited){const parts=date.value.split('-').map(Number); const d=new Date(parts[0],parts[1]-1,parts[2]);title.value=`Scheduled Power Interruption ${new Intl.DateTimeFormat('en-US',{month:'long',day:'numeric',year:'numeric'}).format(d)}`;}const confirmation=form.querySelector('[name="date_confirmed"]');if(confirmation)confirmation.checked=false;render();});
 let objectUrl;document.getElementById('poster')?.addEventListener('change',event=>{const file=event.target.files[0];const image=document.getElementById('poster-preview');image.hidden=true;document.getElementById('poster-placeholder').hidden=false;if(objectUrl)URL.revokeObjectURL(objectUrl);if(!file)return;if(!['image/jpeg','image/png','image/webp'].includes(file.type)||file.size>8*1024*1024){event.target.setCustomValidity('Choose a JPG, PNG, or WebP image up to 8 MB.');event.target.reportValidity();return;}event.target.setCustomValidity('');objectUrl=URL.createObjectURL(file);image.src=objectUrl;image.hidden=false;document.getElementById('poster-placeholder').hidden=true;const confirm=form.querySelector('[name="date_confirmed"]');if(confirm)confirm.checked=false;});
 form.addEventListener('submit',e=>{if(!title.value.trim()){if(body)detect();}if(!form.checkValidity()){e.preventDefault();form.reportValidity();}});
}
const publishDialog=document.getElementById('publish-dialog');
document.getElementById('open-publish')?.addEventListener('click',()=>publishDialog.showModal());
document.getElementById('cancel-publish')?.addEventListener('click',()=>publishDialog.close());
document.querySelectorAll('[data-publishing-form]').forEach(form=>form.addEventListener('submit',()=>{form.querySelectorAll('button[type=submit]').forEach(button=>{button.disabled=true;button.textContent='Processing WordPress request…';});}));
if('serviceWorker' in navigator)navigator.serviceWorker.register(`${base}/sw.js`,{scope:`${base}/`}).catch(()=>{});
let installPrompt;window.addEventListener('beforeinstallprompt',event=>{event.preventDefault();installPrompt=event;const button=document.getElementById('install-app');if(button)button.hidden=false;});
document.getElementById('install-app')?.addEventListener('click',async()=>{if(!installPrompt)return;await installPrompt.prompt();installPrompt=null;document.getElementById('install-app').hidden=true;});
