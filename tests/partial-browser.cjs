const {chromium}=require('../storage/qa/node_modules/playwright');
const assert=require('node:assert/strict');const fs=require('node:fs');const path=require('node:path');
(async()=>{
 const [base,cookie,fixture]=process.argv.slice(2);const browser=await chromium.launch({channel:'chrome',headless:true});
 const mode=value=>{const s=JSON.parse(fs.readFileSync(fixture,'utf8'));s.mode=value;fs.writeFileSync(fixture,JSON.stringify(s));};
 const postCount=()=>JSON.parse(fs.readFileSync(fixture,'utf8')).calls.filter(c=>c.method==='POST'&&c.path==='/posts').length;
 try{
 const context=await browser.newContext({viewport:{width:390,height:844}});await context.route('**/*',route=>new URL(route.request().url()).origin===new URL(base).origin?route.continue():route.abort());await context.addCookies([{name:'PALECOHUB',value:cookie,url:base+'/',httpOnly:true,sameSite:'Lax'}]);
 const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto(base+'/partial');
 assert.deepEqual(await page.locator('#announcement-form button').allTextContents(),['Publish','Cancel']);
 let dialogCount=0;const clean=()=>dialogCount++;page.on('dialog',clean);await page.getByRole('button',{name:'Cancel',exact:true}).click();await page.waitForURL('**/history');assert.equal(dialogCount,0);page.off('dialog',clean);
 await page.goto(base+'/partial');const body=page.locator('#body'),title=page.locator('#title');
 await body.evaluate(el=>{el.dispatchEvent(new Event('paste',{bubbles:true}));el.value='⚡️ Power Service Advisory Final Update\n\nRestored in Bárangay Maligaya.';el.dispatchEvent(new InputEvent('input',{bubbles:true,inputType:'insertFromPaste'}));});await page.waitForFunction(()=>document.getElementById('title').value==='⚡️ Power Service Advisory Final Update');
 await title.fill('My manual title');await body.fill('Power Service Advisory New Update\n\nNew content.');await page.waitForResponse(r=>r.url().endsWith('/partial/title'));assert.equal(await title.inputValue(),'My manual title');
 await title.fill('');await page.waitForFunction(()=>document.getElementById('title').value==='Power Service Advisory New Update');
 await body.fill('');assert.equal(await title.inputValue(),'');await body.fill('Supply restored in Maligaya.');await page.waitForFunction(()=>document.getElementById('title').value.includes('Supply restored in Maligaya.'));
 page.once('dialog',d=>d.dismiss());await page.getByRole('button',{name:'Cancel',exact:true}).click();assert.equal(await body.inputValue(),'Supply restored in Maligaya.');
 page.once('dialog',d=>d.accept());await page.getByRole('button',{name:'Cancel',exact:true}).click();await page.waitForURL('**/history');
 // Delay one detection response and edit both content and title while it is in flight.
 await page.goto(base+'/partial');let delayedResolve;const held=new Promise(resolve=>delayedResolve=resolve);
 await page.route('**/partial/title',async route=>{if(route.request().postData().includes('Old')){const response=await route.fetch();await held;await route.fulfill({response});}else await route.continue();});
 await body.fill('Power Service Advisory Old\nOld body.');await page.waitForRequest(r=>r.url().endsWith('/partial/title'));await body.fill('Power Service Advisory Latest\nLatest body.');await title.fill('Manual latest title');delayedResolve();
 await page.waitForResponse(r=>r.url().endsWith('/partial/title'));assert.equal(await title.inputValue(),'Manual latest title');await page.unroute('**/partial/title');
 const before=postCount();await page.getByRole('button',{name:'Publish',exact:true}).click();await page.locator('#partial-confirm').waitFor({state:'visible'});assert.ok((await page.locator('#partial-confirm').textContent()).includes('publicly visible'));assert.ok((await page.locator('#partial-confirm').textContent()).includes('POWER_ADVISORY'));assert.equal(postCount(),before);
 await page.getByRole('button',{name:'Go back',exact:true}).click();assert.equal(postCount(),before);assert.equal(await title.inputValue(),'Manual latest title');
 mode('auth');await page.getByRole('button',{name:'Publish',exact:true}).click();await page.locator('#partial-confirm').waitFor({state:'visible'});await page.getByRole('button',{name:'Confirm and publish'}).click();await page.waitForURL('**/partial/publish');assert.equal(await title.inputValue(),'Manual latest title');assert.ok((await page.locator('[role=status]').textContent()).includes('authentication failed'));
 mode('post-timeout');await page.getByRole('button',{name:'Publish',exact:true}).click();await page.locator('#partial-confirm').waitFor({state:'visible'});await page.getByRole('button',{name:'Confirm and publish'}).click();await page.waitForLoadState('networkidle');assert.equal(await title.inputValue(),'Manual latest title');const accepted=postCount();
 await page.getByRole('button',{name:'Publish',exact:true}).click();await page.locator('#partial-confirm').waitFor({state:'visible'});await page.getByRole('button',{name:'Confirm and publish'}).click();await page.waitForURL('**/announcement?id=*');await page.getByRole('link',{name:'View on WordPress',exact:true}).waitFor();assert.equal(postCount(),accepted);
 await page.goto(base+'/partial');await body.fill('Power Service Advisory Mobile\n\nPreview details.');await page.waitForFunction(()=>document.getElementById('title').value==='Power Service Advisory Mobile');await page.screenshot({path:path.join(__dirname,'../storage/screenshots/partial-publish-mobile.png'),fullPage:true});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));assert.deepEqual(errors,[]);
 console.log('BROWSER PASS: automatic/fallback titles, manual/reset behavior, stale response safety, clean/dirty Cancel, confirmation cancellation, preserved errors and duplicate-safe retry.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
