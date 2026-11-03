'use strict';
(()=>{
 const form=document.querySelector('#announcement-form[data-type=scheduled]');if(!form)return;
 const input=document.getElementById('poster'),title=document.getElementById('title'),date=document.getElementById('date'),selected=document.getElementById('selected-posters'),gallery=document.getElementById('poster-preview-gallery'),error=document.getElementById('poster-selection-error');
 const max=Number(form.dataset.maxPosters);let files=[],urls=[],manual=form.dataset.customTitle==='1',generated=title.value,dateManual=!!date.value&&form.elements.date_source.value!=='ocr',sequence=0,request,ocrResults=[],scanning=false,publishing=false,attempted=false;
 const kept=()=>[...document.querySelectorAll('[data-saved-poster]')].filter(el=>!el.querySelector('[data-remove-saved]').checked);
 const generate=()=>{if(date.value&&!manual){const [y,m,d]=date.value.split('-').map(Number);generated=`Scheduled Power Interruption [${new Intl.DateTimeFormat('en-US',{month:'long',day:'numeric',year:'numeric'}).format(new Date(y,m-1,d))}]`;title.value=generated;}document.getElementById('preview-title').textContent=title.value||'Your announcement title';};
 const gate=()=>{const valid=ocrResults.filter(r=>r.status==='valid'),count=kept().length+files.length,conflict=new Set(valid.map(r=>r.date)).size>1||ocrResults.some(r=>['conflict','weekday_mismatch'].includes(r.status));form.querySelectorAll('button[type=submit]').forEach(button=>button.disabled=publishing||(attempted&&button.value!=='review')||(button.name==='next'&&(scanning||!count||conflict||(!dateManual&&valid.length!==count))));};
 const invalidateDate=()=>{if(!dateManual){date.value='';form.elements.date_source.value='manual';}request?.abort();sequence++;};
 const sync=()=>{const transfer=new DataTransfer();files.forEach(f=>transfer.items.add(f));input.files=transfer.files;input.required=kept().length===0;};
 const image=(url,label)=>{const img=document.createElement('img');img.src=url;img.alt=label;img.className='poster-preview';return img;};
 const render=()=>{
  urls.forEach(url=>URL.revokeObjectURL(url));urls=files.map(file=>URL.createObjectURL(file));selected.replaceChildren();gallery.replaceChildren();
  const sources=kept().map(el=>el.dataset.url);
  files.forEach((file,index)=>{const tile=document.createElement('div');tile.className='poster-tile';tile.append(image(urls[index],`Selected poster ${index+1}`));const name=document.createElement('small');name.textContent=file.name;tile.append(name);const remove=document.createElement('button');remove.type='button';remove.className='btn btn-sm btn-outline-danger';remove.textContent=`Remove poster ${index+1}`;remove.addEventListener('click',()=>{files.splice(index,1);invalidateDate();sync();render();scan();});tile.append(remove);selected.append(tile);});
  sources.push(...urls);sources.forEach((url,index)=>{const img=image(url,`Publication poster ${index+1}`);if(index===0)img.id='poster-preview';gallery.append(img);});
  document.getElementById('poster-placeholder').hidden=sources.length>0;document.getElementById('poster-counter').textContent=`${sources.length} poster${sources.length===1?'':'s'} selected (maximum ${max})`;
 };
 const add=incoming=>{
  const next=[...files,...incoming];let message='';
  if(kept().length+next.length>max)message=`Select no more than ${max} posters.`;
  else if(next.some(f=>!['image/jpeg','image/png','image/webp'].includes(f.type)||f.size>8*1024*1024||f.size===0))message='Each poster must be JPG, PNG or WebP and no larger than 8 MB.';
  error.textContent=message;error.hidden=!message;if(!message){files=next;invalidateDate();}sync();render();if(!message)scan();
 };
 input.addEventListener('change',()=>add([...input.files]));
 const drop=document.getElementById('poster-drop');drop.addEventListener('dragover',e=>{e.preventDefault();drop.classList.add('drag-active');});drop.addEventListener('dragleave',()=>drop.classList.remove('drag-active'));drop.addEventListener('drop',e=>{e.preventDefault();drop.classList.remove('drag-active');add([...e.dataTransfer.files]);});
 document.querySelectorAll('[data-remove-saved]').forEach(box=>box.addEventListener('change',()=>{invalidateDate();sync();render();scan();}));
 title.addEventListener('input',()=>{manual=!!title.value.trim()&&title.value!==generated;generate();});
 const manualDate=()=>{dateManual=true;form.elements.date_source.value='manual';generate();gate();};date.addEventListener('input',manualDate);date.addEventListener('change',manualDate);
 async function scan(){
  request?.abort();const token=++sequence,controller=new AbortController();request=controller;let timedOut=false;const timeout=setTimeout(()=>{timedOut=true;controller.abort();},45000);const summary=document.getElementById('ocr-summary'),list=document.getElementById('ocr-posters');list.replaceChildren();ocrResults=[];
  if(!kept().length&&!files.length){clearTimeout(timeout);scanning=false;summary.textContent='Select posters to recognize their printed interruption dates.';gate();return;}
  scanning=true;summary.textContent='Detecting interruption date...';gate();
  const data=new FormData();data.set('_token',form.elements._token.value);data.set('id',form.elements.id.value);form.querySelectorAll('[data-remove-saved]:checked').forEach(box=>data.append('remove_attachments[]',box.value));files.forEach(file=>data.append('posters[]',file));
  try{const response=await fetch(`${document.body.dataset.base}/scheduled/recognize`,{method:'POST',body:data,signal:controller.signal,credentials:'same-origin'});let result;try{result=await response.json();}catch{throw Error('The OCR response could not be read. Check your session and enter the date manually.');}if(token!==sequence)return;if(result.usage){const u=result.usage;document.getElementById('ocr-usage').textContent=u.available?`OCR ${u.month} (Asia/Manila): ${u.used} / ${u.limit} units used; ${u.remaining} remaining.`:u.message;}if(!response.ok)throw Error(result.error||'Scanning failed.');ocrResults=result.posters;
   const dates=[...new Set(ocrResults.filter(r=>r.status==='valid').map(r=>r.date))];const all=ocrResults.every(r=>r.status==='valid')&&dates.length===1;
   if(all){if(!dateManual){date.value=dates[0];form.elements.date_source.value='ocr';generate();}summary.textContent=dateManual?'Dates scanned. Your manually entered interruption date is retained.':`Interruption date detected: ${new Intl.DateTimeFormat('en-US',{month:'long',day:'numeric',year:'numeric',timeZone:'UTC'}).format(new Date(dates[0]+'T00:00:00Z'))}`;}
   else summary.textContent=dates.length>1?'Posters show different interruption dates. Remove mismatched posters before publishing.':(ocrResults.find(r=>r.status!=='valid')?.message||'Review the poster results and enter the date manually.');
   ocrResults.forEach((r,i)=>{const item=document.createElement('li');item.textContent=`Poster ${i+1}: ${r.candidates.length?r.candidates.join(', ')+' — ':''}${r.message}`;list.append(item);});gate();
  }catch(ex){if(token!==sequence||(ex.name==='AbortError'&&!timedOut))return;ocrResults=[{status:'unavailable',candidates:[]}];summary.textContent=timedOut?'Date detection timed out. Read every poster and enter the interruption date manually.':(ex instanceof TypeError?'OCR could not be reached. Check your connection or enter the date manually.':ex.message);gate();}finally{clearTimeout(timeout);if(token===sequence){scanning=false;gate();}}
 }
 const dialog=document.getElementById('scheduled-confirm'),confirm=document.getElementById('scheduled-confirm-publish'),success=document.getElementById('scheduled-success');let pending;
 const resetScheduledFormAfterPublish=operation=>{
  request?.abort();sequence++;attempted=false;files=[];ocrResults=[];scanning=false;manual=false;dateManual=false;generated='';pending=null;
  document.getElementById('saved-posters')?.remove();form.reset();form.querySelectorAll('input').forEach(el=>el.setCustomValidity(''));form.classList.remove('was-validated');input.value='';date.value='';title.value='';
  form.elements.id.value='0';form.elements.revision.value='0';form.elements.operation.value=operation;form.elements.confirmed.value='0';form.elements.date_source.value='manual';form.dataset.editing='';form.dataset.customTitle='0';
  error.textContent='';error.hidden=true;document.getElementById('ocr-posters').replaceChildren();document.getElementById('ocr-summary').textContent='Select posters to recognize their printed interruption dates.';
  document.getElementById('poster-drop').classList.remove('drag-active');sync();render();generate();gate();
 };
 form.addEventListener('submit',e=>{
  if(publishing){e.preventDefault();return;}if(attempted&&e.submitter?.value!=='review'){e.preventDefault();return;}sync();
  if(kept().length+files.length<1||kept().length+files.length>max){e.preventDefault();error.textContent=`Select between 1 and ${max} posters.`;error.hidden=false;return;}
  if(!form.checkValidity()){e.preventDefault();form.reportValidity();return;}
  if(e.submitter?.value!=='review')return;
  e.preventDefault();if(scanning||e.submitter.disabled)return;
  pending=new FormData(form);pending.set('confirmed','1');
  document.getElementById('scheduled-confirm-summary').textContent=`${title.value} | Interruption date: ${date.value} | ${kept().length+files.length} poster(s). Category: INTERRUPTIONS. This post will be public.`;
  dialog.showModal();
 });
 document.getElementById('scheduled-confirm-cancel').addEventListener('click',()=>{pending=null;dialog.close();});
 dialog.addEventListener('cancel',e=>{if(publishing)e.preventDefault();else pending=null;});
 confirm.addEventListener('click',async()=>{
  if(publishing||!pending)return;attempted=true;publishing=true;confirm.disabled=true;document.getElementById('scheduled-confirm-cancel').disabled=true;form.inert=true;gate();confirm.textContent='Publishing...';error.hidden=true;success.hidden=true;
  try{
   const response=await fetch(`${document.body.dataset.base}/scheduled/publish`,{method:'POST',body:pending,credentials:'same-origin',headers:{Accept:'application/json'}});
   let result;try{result=await response.json();}catch{throw Error('The result is uncertain or your session expired. Keep this form and retry, or inspect Publishing History before starting another submission.');}
   if(!response.ok)throw Error(result.error||'Publication failed. Retry this same submission.');
   if(result.status!=='published'||result.wordpress_status!=='publish'||!Number.isInteger(result.wordpress_post_id)||result.wordpress_post_id<=0||result.history_finalized!==true||!/^[a-f0-9]{64}$/.test(result.next_operation||''))throw Error('Publication is not finalized. Retry this same form to reconcile its result.');
   resetScheduledFormAfterPublish(result.next_operation);success.textContent='Scheduled Power Interruption published successfully. Ready for another announcement.';
   if(result.wordpress_post_url){const link=new URL(result.wordpress_post_url);if(link.protocol==='https:'&&!link.username&&!link.password){const view=document.createElement('a');view.href=link.href;view.textContent='View Published Post';view.target='_blank';view.rel='noopener noreferrer';view.className='ms-2';success.append(' ',view);}}success.hidden=false;
  }catch(ex){error.textContent=ex instanceof TypeError?'The result is uncertain. Retry this same form to reconcile it without another post.':ex.message;error.textContent+=' Retry this unchanged submission, or open Publishing History to reconcile or edit its saved record.';error.hidden=false;}
  finally{publishing=false;form.inert=false;confirm.disabled=false;document.getElementById('scheduled-confirm-cancel').disabled=false;confirm.textContent='Confirm & Publish';dialog.close();gate();}
 });
 render();if(kept().length)scan();
})();
