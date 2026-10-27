'use strict';
(()=>{
 const form=document.querySelector('#announcement-form[data-type=scheduled]');if(!form)return;
 const input=document.getElementById('poster'),title=document.getElementById('title'),date=document.getElementById('date'),selected=document.getElementById('selected-posters'),gallery=document.getElementById('poster-preview-gallery'),error=document.getElementById('poster-selection-error');
 const max=Number(form.dataset.maxPosters);let files=[],urls=[],manual=!!form.dataset.editing;
 const kept=()=>[...document.querySelectorAll('[data-saved-poster]')].filter(el=>!el.querySelector('[data-remove-saved]').checked);
 const invalidateDate=()=>{form.elements.date_confirmed.checked=false;form.elements.date_conflict_confirmed.checked=false;};
 const sync=()=>{const transfer=new DataTransfer();files.forEach(f=>transfer.items.add(f));input.files=transfer.files;input.required=kept().length===0;};
 const image=(url,label)=>{const img=document.createElement('img');img.src=url;img.alt=label;img.className='poster-preview';return img;};
 const render=()=>{
  urls.forEach(url=>URL.revokeObjectURL(url));urls=files.map(file=>URL.createObjectURL(file));selected.replaceChildren();gallery.replaceChildren();
  const sources=kept().map(el=>el.dataset.url);
  files.forEach((file,index)=>{const tile=document.createElement('div');tile.className='poster-tile';tile.append(image(urls[index],`Selected poster ${index+1}`));const name=document.createElement('small');name.textContent=file.name;tile.append(name);const remove=document.createElement('button');remove.type='button';remove.className='btn btn-sm btn-outline-danger';remove.textContent=`Remove poster ${index+1}`;remove.addEventListener('click',()=>{files.splice(index,1);invalidateDate();sync();render();});tile.append(remove);selected.append(tile);});
  sources.push(...urls);sources.forEach((url,index)=>{const img=image(url,`Publication poster ${index+1}`);if(index===0)img.id='poster-preview';gallery.append(img);});
  document.getElementById('poster-placeholder').hidden=sources.length>0;document.getElementById('poster-counter').textContent=`${sources.length} poster${sources.length===1?'':'s'} selected (maximum ${max})`;
 };
 const add=incoming=>{
  const next=[...files,...incoming];let message='';
  if(kept().length+next.length>max)message=`Select no more than ${max} posters.`;
  else if(next.some(f=>!['image/jpeg','image/png','image/webp'].includes(f.type)||f.size>8*1024*1024||f.size===0))message='Each poster must be JPG, PNG or WebP and no larger than 8 MB.';
  error.textContent=message;error.hidden=!message;if(!message){files=next;invalidateDate();}sync();render();
 };
 input.addEventListener('change',()=>add([...input.files]));
 const drop=document.getElementById('poster-drop');drop.addEventListener('dragover',e=>{e.preventDefault();drop.classList.add('drag-active');});drop.addEventListener('dragleave',()=>drop.classList.remove('drag-active'));drop.addEventListener('drop',e=>{e.preventDefault();drop.classList.remove('drag-active');add([...e.dataTransfer.files]);});
 document.querySelectorAll('[data-remove-saved]').forEach(box=>box.addEventListener('change',()=>{invalidateDate();sync();render();}));
 title.addEventListener('input',()=>{manual=!!title.value.trim();document.getElementById('preview-title').textContent=title.value||'Your announcement title';});
 date.addEventListener('change',()=>{if(date.value&&!manual){const [year,month,day]=date.value.split('-').map(Number);title.value=`Scheduled Power Interruption ${new Intl.DateTimeFormat('en-US',{month:'long',day:'numeric',year:'numeric'}).format(new Date(year,month-1,day))}`;}document.getElementById('preview-title').textContent=title.value||'Your announcement title';invalidateDate();});
 form.addEventListener('submit',e=>{sync();if(kept().length+files.length<1||kept().length+files.length>max){e.preventDefault();error.textContent=`Select between 1 and ${max} posters.`;error.hidden=false;}else if(!form.checkValidity()){e.preventDefault();form.reportValidity();}});
 render();
})();
