'use strict';
(()=>{
 const form=document.querySelector('#announcement-form[data-type="partial"]');if(!form)return;
 const body=form.elements.body,title=form.elements.title,publish=document.getElementById('partial-publish'),cancel=document.getElementById('partial-cancel');
 const success=document.getElementById('partial-success'),error=document.getElementById('partial-error'),status=document.getElementById('title-status');
 const initial=()=>JSON.stringify([body.value,title.value]);let original=initial();
 let manual=!!title.value.trim(),sequence=0,timer,request,submitting=false;
 const setError=message=>{error.textContent=message;error.hidden=!message;};
 const invalidate=()=>{form.elements.confirmed.value='0';form.elements.confirmation_token.value='';};
 const render=()=>{document.getElementById('preview-title').textContent=title.value||'Your announcement title';document.getElementById('preview-body').textContent=body.value;};
 const post=async(path,data,signal)=>{const response=await fetch(`${document.body.dataset.base}${path}`,{method:'POST',body:data,credentials:'same-origin',signal,headers:{Accept:'application/json'}});let json;try{json=await response.json();}catch{throw Error('This session or form expired. Reload after copying your unsaved advisory, then try again.');}if(!response.ok)throw Error(json.error||'The request failed. Check your advisory and try again.');return json;};
 const detect=async()=>{
  clearTimeout(timer);request?.abort();const current=++sequence,text=body.value,controller=new AbortController();request=controller;
  if(!text.trim()){manual=false;title.value='';render();return;}
  const data=new FormData();data.set('_token',form.elements._token.value);data.set('body',text);
  try{const result=await post('/partial/title',data,controller.signal);if(current!==sequence||body.value!==text)return;if(!manual)title.value=result.title;status.textContent=manual?'Your edited title is retained. Clear it to resume automatic detection.':'Title detected automatically. You can edit it; clearing the advisory starts fresh.';render();}
  catch(ex){if(ex.name!=='AbortError'&&current===sequence)status.textContent='Automatic detection is unavailable. Enter a title or let Publish generate it on the server.';}
 };
 body.addEventListener('input',event=>{invalidate();request?.abort();sequence++;render();clearTimeout(timer);if(!body.value.trim()){manual=false;title.value='';render();return;}timer=setTimeout(detect,event.inputType==='insertFromPaste'?0:250);});
 body.addEventListener('paste',()=>{
  // Replacing the whole advisory starts a new announcement; an inline paste is an edit.
  if(body.selectionStart===0&&body.selectionEnd===body.value.length){manual=false;title.value='';invalidate();request?.abort();sequence++;}
  clearTimeout(timer);timer=setTimeout(detect,0);
 });
 title.addEventListener('input',()=>{invalidate();manual=!!title.value.trim();request?.abort();sequence++;render();clearTimeout(timer);timer=setTimeout(detect,manual?250:0);});
 cancel.addEventListener('click',()=>{if(initial()!==original&&!window.confirm('Discard your unsaved advisory changes?'))return;request?.abort();window.location.assign(cancel.dataset.url);});
 const resetPowerAdvisoryFormAfterPublish=operation=>{
  clearTimeout(timer);request?.abort();sequence++;invalidate();manual=false;
  body.value='';title.value='';body.setCustomValidity('');title.setCustomValidity('');
  for(const name of ['id','revision','operation_revision','confirmed'])form.elements[name].value='0';
  form.elements.operation.value=operation;form.elements.confirmation_token.value='';form.dataset.editing='';
  original=initial();setError('');status.textContent='Edit the title to override detection. Clear it to resume automatic detection; clearing the advisory starts fresh.';render();
  body.focus({preventScroll:true});
 };
 form.addEventListener('submit',async event=>{
  event.preventDefault();if(submitting)return;
  if(!body.value.trim()){setError('Enter the advisory text before publishing.');body.focus();return;}
  if(!form.reportValidity())return;
  clearTimeout(timer);request?.abort();sequence++;setError('');success.hidden=true;submitting=true;publish.disabled=true;cancel.disabled=true;body.readOnly=true;title.readOnly=true;publish.textContent='Publishing?';
  try{
   const result=await post('/partial/prepare',new FormData(form));
   title.value=result.title;form.elements.operation_revision.value=result.operation_revision;form.elements.confirmation_token.value=result.confirmation_token;form.elements.confirmed.value='1';render();
   const published=await post('/partial/publish',new FormData(form));
   if(published.status!=='published'||published.wordpress_status!=='publish'||!Number.isInteger(published.wordpress_post_id)||published.wordpress_post_id<=0||published.history_finalized!==true||!/^[a-f0-9]{64}$/.test(published.next_operation||''))throw Error('Publication is not confirmed. Retry this same form to reconcile its result.');
   resetPowerAdvisoryFormAfterPublish(published.next_operation);
   success.textContent='Power interruption update published successfully. Ready for another update.';success.hidden=false;
  }catch(ex){setError(ex instanceof TypeError?'The result is uncertain. Retry this same form to reconcile it.':ex.message);}
  finally{submitting=false;publish.disabled=false;cancel.disabled=false;body.readOnly=false;title.readOnly=false;publish.textContent='Publish';}
 });
 if(body.value.trim())timer=setTimeout(detect,0);
})();
