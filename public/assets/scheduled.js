'use strict';
(()=>{
 const form=document.querySelector('#announcement-form[data-type=scheduled]');if(!form)return;
 const input=document.getElementById('poster'),title=document.getElementById('title'),date=document.getElementById('date'),selected=document.getElementById('selected-posters'),gallery=document.getElementById('poster-preview-gallery'),error=document.getElementById('poster-selection-error');
 const max=Number(form.dataset.maxPosters);let files=[],urls=[],manual=form.dataset.customTitle==='1',generated=title.value,dateManual=!!date.value&&form.elements.date_source.value!=='ocr',sequence=0,request,ocrResults=[],scanning=false;
 const kept=()=>[...document.querySelectorAll('[data-saved-poster]')].filter(el=>!el.querySelector('[data-remove-saved]').checked);
 const generate=()=>{if(date.value&&!manual){const [y,m,d]=date.value.split('-').map(Number);generated=`Scheduled Power Interruption [${new Intl.DateTimeFormat('en-US',{month:'long',day:'numeric',year:'numeric'}).format(new Date(y,m-1,d))}]`;title.value=generated;}document.getElementById('preview-title').textContent=title.value||'Your announcement title';};
 const gate=()=>{const valid=ocrResults.filter(r=>r.status==='valid'),count=kept().length+files.length,conflict=new Set(valid.map(r=>r.date)).size>1||ocrResults.some(r=>['conflict','weekday_mismatch'].includes(r.status));form.querySelectorAll('button[name=next]').forEach(button=>button.disabled=scanning||!count||conflict||(!dateManual&&valid.length!==count));};
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
  request?.abort();const token=++sequence;request=new AbortController();const summary=document.getElementById('ocr-summary'),list=document.getElementById('ocr-posters');list.replaceChildren();ocrResults=[];
  if(!kept().length&&!files.length){scanning=false;summary.textContent='Select posters to recognize their printed interruption dates.';gate();return;}
  scanning=true;summary.textContent='Scanning each poster…';gate();
  const data=new FormData();data.set('_token',form.elements._token.value);data.set('id',form.elements.id.value);form.querySelectorAll('[data-remove-saved]:checked').forEach(box=>data.append('remove_attachments[]',box.value));files.forEach(file=>data.append('posters[]',file));
  try{const response=await fetch(`${document.body.dataset.base}/scheduled/recognize`,{method:'POST',body:data,signal:request.signal,credentials:'same-origin'});const result=await response.json();if(token!==sequence)return;if(!response.ok)throw Error(result.error||'Scanning failed.');ocrResults=result.posters;
   const dates=[...new Set(ocrResults.filter(r=>r.status==='valid').map(r=>r.date))];const all=ocrResults.every(r=>r.status==='valid')&&dates.length===1;
   if(all){if(!dateManual){date.value=dates[0];form.elements.date_source.value='ocr';generate();}summary.textContent=dateManual?'Dates scanned. Your manually entered interruption date is retained.':'All posters have the same labelled interruption date. The date has been filled automatically.';}
   else summary.textContent='Review the poster results below. Remove conflicting posters; enter the actual date manually for unreadable/unavailable scans.';
   ocrResults.forEach((r,i)=>{const item=document.createElement('li');item.textContent=`Poster ${i+1}: ${r.candidates.length?r.candidates.join(', ')+' — ':''}${r.message}`;list.append(item);});gate();
  }catch(ex){if(ex.name==='AbortError'||token!==sequence)return;ocrResults=[{status:'unavailable'}];summary.textContent='OCR is unavailable. Read every poster and enter the interruption date manually.';gate();}finally{if(token===sequence){scanning=false;gate();}}
 }
 form.addEventListener('submit',e=>{sync();if(kept().length+files.length<1||kept().length+files.length>max){e.preventDefault();error.textContent=`Select between 1 and ${max} posters.`;error.hidden=false;}else if(!form.checkValidity()){e.preventDefault();form.reportValidity();}});
 render();if(kept().length)scan();
})();
