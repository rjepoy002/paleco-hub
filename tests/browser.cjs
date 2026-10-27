const {chromium}=require('../storage/qa/node_modules/playwright');
const fs=require('fs');
const path=require('path');
async function main(){
 const [base,adminCookie]=process.argv.slice(2);
 const browser=await chromium.launch({channel:'chrome',headless:true});
 const errors=[];
 const context=await browser.newContext({viewport:{width:1440,height:1050}});
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 const screenshots=path.join(__dirname,'../storage/screenshots');fs.mkdirSync(screenshots,{recursive:true});
 const assert=(value,message)=>{if(!value)throw Error(message);console.log('BROWSER PASS: '+message);};
 await page.goto(base+'/');await page.getByRole('button',{name:'Continue with Google'}).waitFor();
 assert(await page.locator('.login-logo').evaluate(img=>img.complete&&img.naturalWidth>0),'official logo loads');
 assert(await page.locator('h1').evaluate(el=>getComputedStyle(el).fontSize)==='39px','application stylesheet loads');
 await page.screenshot({path:path.join(screenshots,'login-desktop.png'),fullPage:true});
 await context.addCookies([{name:'PALECOHUB',value:adminCookie,url:base+'/',httpOnly:true,sameSite:'Lax'}]);
 await page.goto(base+'/dashboard');await page.getByRole('heading',{name:'Create an announcement'}).waitFor();
 await page.screenshot({path:path.join(screenshots,'dashboard-desktop.png'),fullPage:true});
 await page.goto(base+'/partial');
 const text='Power Service Advisory Final Update 10/23/26 12:00 PM\n\nPower restored.\nThank you.';
 await page.locator('#body').fill(text);
 await page.waitForFunction(()=>document.getElementById('title').value==='Power Service Advisory Final Update 10/23/26 12:00 PM');
 assert(await page.locator('#title').inputValue()==='Power Service Advisory Final Update 10/23/26 12:00 PM','browser preserves detected original title');
 assert(await page.locator('#preview-body').textContent()==='Power restored.\nThank you.','browser preview preserves details without duplicating the title');
 await page.locator('#title').fill('Edited advisory title');await page.locator('#body').fill(text+'\nAdditional line.');
 assert(await page.locator('#title').inputValue()==='Edited advisory title','manual title edits survive body changes');
 assert(await page.locator('#announcement-form button').count()===2,'partial main form has exactly Publish and Cancel actions');
 await page.goto(base+'/scheduled');await page.locator('#poster').setInputFiles(path.join(__dirname,'../public/assets/paleco-logo.png'));
 await page.locator('#poster-preview').waitFor({state:'visible'});assert(await page.locator('#title').inputValue()==='','poster selection does not invent a date');
 await page.locator('#date').fill('2026-10-25');await page.locator('#date').dispatchEvent('change');
 assert(await page.locator('#title').inputValue()==='Scheduled Power Interruption October 25, 2026','scheduled title uses the confirmed date');
 await page.locator('[name=date_confirmed]').check();await page.getByRole('button',{name:'Save Local Draft',exact:true}).click();await page.waitForURL('**/history');assert(await page.getByText('Scheduled Power Interruption October 25, 2026',{exact:true}).isVisible(),'image draft saved');
 await page.getByText('View poster',{exact:true}).click();const poster=page.locator('.history-poster');await poster.waitFor({state:'visible'});await poster.evaluate(img=>img.decode());assert(await poster.evaluate(img=>img.naturalWidth>0),'private poster loads for authenticated user');
 for(const viewport of [{width:390,height:844},{width:360,height:800},{width:768,height:1024}]){
   await page.setViewportSize(viewport);await page.goto(base+'/dashboard');
   await page.getByRole('button',{name:'Open navigation'}).click();await page.waitForFunction(()=>document.getElementById('sidebar').getBoundingClientRect().left===0);assert(true,'mobile navigation opens at '+viewport.width+'px');
   await page.getByRole('button',{name:'Close navigation'}).click({position:{x:viewport.width-12,y:20}});
   await page.waitForFunction(()=>document.getElementById('sidebar').getBoundingClientRect().right<=0);
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no dashboard horizontal overflow at '+viewport.width+'px');
   if(viewport.width===390)await page.screenshot({path:path.join(screenshots,'dashboard-mobile.png'),fullPage:true});
   for(const route of ['/partial','/scheduled','/wordpress','/security','/users']){await page.goto(base+route);assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),route+' fits '+viewport.width+'px');}
 }
 await context.clearCookies();await page.goto(base+'/');await page.screenshot({path:path.join(screenshots,'login-mobile.png'),fullPage:true});
 await page.evaluate(()=>navigator.serviceWorker.ready);assert(await page.evaluate(async()=>{const keys=await caches.keys();for(const k of keys){const cache=await caches.open(k);for(const request of await cache.keys())if(!new URL(request.url).pathname.includes('/assets/'))return false;}return true;}),'service worker caches only public assets');
 assert(errors.length===0,'no JavaScript errors');
 await browser.close();
}
main().catch(error=>{console.error(error);process.exit(1);});
