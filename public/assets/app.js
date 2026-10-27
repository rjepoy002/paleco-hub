'use strict';
const base = document.body.dataset.base || '';
const menu = document.getElementById('menu-toggle');
const sidebar = document.getElementById('sidebar');
const backdrop = document.getElementById('nav-backdrop');
function closeMenu() { sidebar?.classList.remove('open'); menu?.setAttribute('aria-expanded','false'); if(backdrop) backdrop.hidden=true; }
menu?.addEventListener('click',()=>{const open=sidebar.classList.toggle('open');menu.setAttribute('aria-expanded',String(open));backdrop.hidden=!open;});
backdrop?.addEventListener('click',closeMenu);
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeMenu();});
const publishDialog=document.getElementById('publish-dialog');
document.getElementById('open-publish')?.addEventListener('click',()=>publishDialog.showModal());
document.getElementById('cancel-publish')?.addEventListener('click',()=>publishDialog.close());
document.querySelectorAll('[data-publishing-form]').forEach(form=>form.addEventListener('submit',()=>{form.querySelectorAll('button[type=submit]').forEach(button=>{button.disabled=true;button.textContent='Processing WordPress request…';});}));
if('serviceWorker' in navigator)navigator.serviceWorker.register(`${base}/sw.js`,{scope:`${base}/`}).catch(()=>{});
let installPrompt;window.addEventListener('beforeinstallprompt',event=>{event.preventDefault();installPrompt=event;const button=document.getElementById('install-app');if(button)button.hidden=false;});
document.getElementById('install-app')?.addEventListener('click',async()=>{if(!installPrompt)return;await installPrompt.prompt();installPrompt=null;document.getElementById('install-app').hidden=true;});
