const {chromium}=require('../storage/qa/node_modules/playwright');
const assert=require('node:assert/strict');
const path=require('node:path');
(async()=>{
 const [base,cookie]=process.argv.slice(2);
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try {
  const context=await browser.newContext({viewport:{width:1440,height:1000}});
  // Stop accidental browser traffic outside the isolated app, including remote post links.
  await context.route('**/*',route=>new URL(route.request().url()).origin===new URL(base).origin?route.continue():route.abort());
  await context.addCookies([{name:'PALECOHUB',value:cookie,url:base+'/',httpOnly:true,sameSite:'Lax'}]);
  const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/partial');await page.locator('#body').fill('Power Service Advisory\n\nBrowser publishing fixture.\nAll text stays safe.');await page.locator('#title').fill('Browser publishing advisory');
  await page.getByRole('button',{name:'Publish',exact:true}).click();await page.locator('#partial-confirm').waitFor({state:'visible'});
  assert.ok(await page.locator('#partial-confirm').getByText('Browser publishing advisory',{exact:true}).isVisible());
  assert.ok(await page.locator('#partial-confirm').getByText('WordPress category: POWER_ADVISORY',{exact:true}).isVisible());
  await page.getByRole('button',{name:'Confirm and publish'}).click();await page.waitForURL('**/announcement?id=*');
  const reviewUrl=page.url();const idBefore=await page.locator('.col-lg-4 section p').first().textContent();
  await page.getByRole('link',{name:'View on WordPress',exact:true}).waitFor();
  assert.equal(await page.locator('.col-lg-4 section p').first().textContent(),idBefore);
  await page.getByRole('link',{name:'Edit local announcement'}).click();await page.locator('#title').fill('Browser edited advisory');await page.getByRole('button',{name:'Publish',exact:true}).click();await page.locator('#partial-confirm').waitFor({state:'visible'});await page.getByRole('button',{name:'Confirm and publish'}).click();await page.waitForURL(reviewUrl);
  await page.getByRole('link',{name:'View on WordPress',exact:true}).waitFor();assert.equal(await page.locator('.col-lg-4 section p').first().textContent(),idBefore);
  await page.screenshot({path:path.join(__dirname,'../storage/screenshots/phase2-review-desktop.png'),fullPage:true});
  for(const width of [390,360,768]){
   await page.setViewportSize({width,height:844});await page.goto(reviewUrl);
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
   await page.getByRole('button',{name:'Publish update to WordPress'}).click();assert.ok(await page.locator('#publish-dialog').isVisible());
   assert.ok(await page.locator('#publish-dialog').evaluate(el=>el.scrollWidth<=el.clientWidth));
   await page.getByRole('button',{name:'Cancel',exact:true}).click();
  }
  await page.screenshot({path:path.join(__dirname,'../storage/screenshots/phase2-review-mobile.png'),fullPage:true});
  await page.goto(base+'/scheduled');await page.locator('#poster').setInputFiles(path.join(__dirname,'../public/assets/paleco-logo.png'));await page.locator('#date').fill('2026-10-25');await page.locator('#date').dispatchEvent('change');await page.locator('[name=date_confirmed]').check();await page.getByRole('button',{name:'Publish to WordPress'}).click();
  await page.getByRole('button',{name:'Publish to WordPress',exact:true}).click();await page.locator('#publish-dialog [name=confirmed]').check();await page.getByRole('button',{name:'Confirm and publish'}).click();await page.getByRole('link',{name:'View on WordPress',exact:true}).waitFor();
  assert.deepEqual(errors,[]);console.log('BROWSER PASS: text draft, modal confirmation, same-post publication/update, poster publishing and mobile review layouts.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error.message);process.exit(1);});
