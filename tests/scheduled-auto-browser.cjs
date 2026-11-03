const {chromium}=require('../storage/qa/node_modules/playwright');
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
(async()=>{
 const [base,cookie,visionFile,fixture]=process.argv.slice(2),browser=await chromium.launch({channel:'chrome',headless:true});
 const state=()=>JSON.parse(fs.readFileSync(fixture,'utf8')),count=()=>state().calls.filter(c=>c.method==='POST'&&c.path==='/posts').length;
 const mode=value=>{const s=state();s.mode=value;fs.writeFileSync(fixture,JSON.stringify(s));};
 try{
  const context=await browser.newContext({viewport:{width:390,height:844}});await context.route('**/*',r=>new URL(r.request().url()).origin===new URL(base).origin?r.continue():r.abort());await context.addCookies([{name:'PALECOHUB',value:cookie,url:base+'/',httpOnly:true,sameSite:'Lax'}]);
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const logo=fs.readFileSync(path.join(__dirname,'../public/assets/paleco-logo.png'));
  const files=[1,2].map(n=>({name:'same-poster.png',mimeType:'image/png',buffer:Buffer.concat([logo,Buffer.from('Scheduled reset fixture '+n)])}));
  const text={};for(const f of files)text[crypto.createHash('sha256').update(f.buffer).digest('hex')]='Interruption Date: October 25, 2026 Sunday';fs.writeFileSync(visionFile,JSON.stringify({text}));
  await page.goto(base+'/scheduled');assert.deepEqual(await page.locator('#announcement-form button[type=submit]').allTextContents(),['Publish']);assert.equal(await page.locator('#announcement-form a').textContent(),'Cancel');
  const upload=async(n)=>{await page.locator('#poster').setInputFiles(files.slice(0,n));await page.waitForFunction(()=>document.querySelector('#date').value==='2026-10-25'&&!document.querySelector('[name=next][value=review]').disabled);assert.equal(await page.locator('#title').inputValue(),'Scheduled Power Interruption [October 25, 2026]');};
  const open=async()=>{await page.getByRole('button',{name:'Publish',exact:true}).click();await page.locator('#scheduled-confirm').waitFor({state:'visible'});};
  const confirm=page.locator('#scheduled-confirm-publish');
  const reset=async()=>{await page.locator('#scheduled-success').waitFor({state:'visible'});await page.locator('#scheduled-confirm').waitFor({state:'hidden'});assert.equal(page.url(),base+'/scheduled');const view=page.getByRole('link',{name:'View Published Post'});assert.match(await view.getAttribute('href'),/^https:\/\/wordpress\.example\.invalid\/\?p=\d+$/);assert.equal(await view.getAttribute('target'),'_blank');assert.equal(await page.locator('#poster').evaluate(el=>el.files.length),0);for(const id of ['date','title'])assert.equal(await page.locator('#'+id).inputValue(),'');for(const id of ['selected-posters','poster-preview-gallery','ocr-posters'])assert.equal(await page.locator('#'+id).evaluate(el=>el.children.length),0);assert.ok(await page.locator('#poster-placeholder').isVisible());};
  await upload(2);const before=count(),operation=await page.locator('[name=operation]').inputValue();await open();assert.ok((await page.locator('#scheduled-confirm-summary').textContent()).includes('2 poster(s)'));assert.equal(count(),before);await page.locator('#scheduled-confirm-cancel').click();assert.equal(await page.locator('#poster').evaluate(el=>el.files.length),2);await open();await confirm.evaluate(el=>{el.click();el.click();});await reset();assert.equal(count(),before+1);assert.notEqual(await page.locator('[name=operation]').inputValue(),operation);
  await upload(1);await open();await confirm.click();await reset();assert.equal(count(),before+2);
  await upload(2);const retry=await page.locator('[name=operation]').inputValue();mode('auth');await open();await confirm.click();await page.locator('#scheduled-confirm').waitFor({state:'hidden'});assert.ok(await page.locator('#poster-selection-error').isVisible());assert.equal(await page.locator('#poster').evaluate(el=>el.files.length),2);assert.equal(await page.locator('[name=operation]').inputValue(),retry);
  mode('post-timeout');await open();await confirm.click();await page.locator('#scheduled-confirm').waitFor({state:'hidden'});assert.equal(await page.locator('#poster').evaluate(el=>el.files.length),2);const accepted=count();await open();await confirm.click();await reset();assert.equal(count(),accepted);
  // Abort a browser response: preserve file objects and OCR for retry.
  await upload(1);await page.route('**/scheduled/publish',r=>r.abort('timedout'));await open();await confirm.click();await page.locator('#scheduled-confirm').waitFor({state:'hidden'});assert.equal(await page.locator('#poster').evaluate(el=>el.files.length),1);assert.equal(await page.locator('#date').inputValue(),'2026-10-25');await page.unroute('**/scheduled/publish');
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));assert.deepEqual(errors,[]);
  console.log('BROWSER PASS: scheduled OCR/title, one confirmation, cancel, double clicks, complete reset, same filename/next publication, preserved files on failure/timeout, safe reconciliation and mobile layout.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
