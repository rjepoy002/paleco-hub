'use strict';
(()=>{
 const form=document.querySelector('#announcement-form[data-type="partial"]');if(!form)return;
 const body=form.elements.body,title=form.elements.title,publish=document.getElementById('partial-publish'),cancel=document.getElementById('partial-cancel');
 const dialog=document.getElementById('partial-confirm'),confirmButton=document.getElementById('partial-confirm-publish'),error=document.getElementById('partial-error'),status=document.getElementById('title-status');
 const initial=()=>JSON.stringify([body.value,title.value]);const original=initial();
 let manual=!!title.value.trim(),sequence=0,timer,request,prepared='',preparing=false,submitting=false,version=0,detectedSource='',detectedLine=null;
 const setError=message=>{error.textContent=message;error.hidden=!message;};
 const invalidate=()=>{version++;prepared='';form.elements.confirmed.value='0';form.elements.confirmation_token.value='';if(dialog.open)dialog.close();};
 const render=()=>{document.getElementById('preview-title').textContent=title.value||'Your announcement title';let text=body.value;if(detectedSource===text&&detectedLine!==null){const lines=text.split(/\r\n|[\n\r\v\f\u0085\u2028\u2029]/u);lines.splice(detectedLine,1);text=lines.join('\n').replace(/^[\r\n]+/,'');}document.getElementById('preview-body').textContent=text;};
 const post=async(path,data,signal)=>{const response=await fetch(`${document.body.dataset.base}${path}`,{method:'POST',body:data,credentials:'same-origin',signal,headers:{Accept:'application/json'}});let json;try{json=await response.json();}catch{throw Error('This session or form expired. Reload after copying your unsaved advisory, then try again.');}if(!response.ok)throw Error(json.error||'The request failed. Check your advisory and try again.');return json;};
 const detect=async()=>{
  clearTimeout(timer);request?.abort();const current=++sequence,text=body.value,controller=new AbortController();request=controller;
  if(!text.trim()){manual=false;title.value='';detectedSource=text;detectedLine=null;render();return;}
  const data=new FormData();data.set('_token',form.elements._token.value);data.set('body',text);
  try{const result=await post('/partial/title',data,controller.signal);if(current!==sequence||body.value!==text)return;detectedSource=text;detectedLine=result.line;if(!manual)title.value=result.title;status.textContent=manual?'Your edited title is retained. Clear it to resume automatic detection.':'Title detected automatically. You can edit it; clearing the advisory starts fresh.';render();}
  catch(ex){if(ex.name!=='AbortError'&&current===sequence)status.textContent='Automatic detection is unavailable. Enter a title or let Publish generate it on the server.';}
 };
 body.addEventListener('input',event=>{invalidate();request?.abort();sequence++;render();clearTimeout(timer);if(!body.value.trim()){manual=false;title.value='';detectedSource='';detectedLine=null;render();return;}timer=setTimeout(detect,event.inputType==='insertFromPaste'?0:250);});
 body.addEventListener('paste',()=>{clearTimeout(timer);timer=setTimeout(detect,0);});
 title.addEventListener('input',()=>{invalidate();manual=!!title.value.trim();request?.abort();sequence++;render();clearTimeout(timer);timer=setTimeout(detect,manual?250:0);});
 cancel.addEventListener('click',()=>{if(initial()!==original&&!window.confirm('Discard your unsaved advisory changes?'))return;request?.abort();window.location.assign(cancel.dataset.url);});
 form.addEventListener('submit',async event=>{
  event.preventDefault();if(submitting||preparing)return;
  if(!body.value.trim()){setError('Enter the advisory text before publishing.');body.focus();return;}
  if(!form.reportValidity())return;
  clearTimeout(timer);request?.abort();sequence++;setError('');preparing=true;publish.disabled=true;
  const captured=initial(),capturedVersion=version;
  try{
   const result=await post('/partial/prepare',new FormData(form));
   if(initial()!==captured||version!==capturedVersion){setError('The advisory changed during review. Choose Publish again to confirm its latest version.');return;}
   title.value=result.title;form.elements.operation_revision.value=result.operation_revision;form.elements.confirmation_token.value=result.confirmation_token;
   document.getElementById('partial-confirm-heading').textContent=result.title;document.getElementById('partial-confirm-body').textContent=result.body;
   document.getElementById('preview-title').textContent=result.title;document.getElementById('preview-body').textContent=result.body;
   prepared=initial();dialog.showModal();
  }catch(ex){setError(ex.message);}finally{preparing=false;if(!submitting)publish.disabled=false;}
 });
 document.getElementById('partial-confirm-cancel').addEventListener('click',()=>dialog.close());
 confirmButton.addEventListener('click',()=>{
  if(submitting)return;
  if(!prepared||prepared!==initial()){invalidate();setError('The advisory changed. Choose Publish to review it again.');return;}
  form.elements.confirmed.value='1';submitting=true;publish.disabled=true;confirmButton.disabled=true;cancel.disabled=true;publish.textContent='Publishing…';confirmButton.textContent='Publishing…';
  HTMLFormElement.prototype.submit.call(form);
 });
 window.addEventListener('pageshow',()=>{submitting=false;preparing=false;publish.disabled=false;confirmButton.disabled=false;cancel.disabled=false;publish.textContent='Publish';confirmButton.textContent='Confirm and publish';form.elements.confirmed.value='0';});
 if(body.value.trim())timer=setTimeout(detect,0);
})();
