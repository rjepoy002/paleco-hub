const {chromium}=require('../storage/qa/node_modules/playwright');
const assert=require('node:assert/strict'),fs=require('node:fs');
(async()=>{
 const [base,cookie,fixture]=process.argv.slice(2),browser=await chromium.launch({channel:'chrome',headless:true});
 const state=()=>JSON.parse(fs.readFileSync(fixture,'utf8'));
 const mode=value=>{const s=state();s.mode=value;fs.writeFileSync(fixture,JSON.stringify(s));};
 const count=()=>state().calls.filter(c=>c.method==='POST'&&c.path==='/posts').length;
 try{
  const context=await browser.newContext({viewport:{width:390,height:844}});
  await context.route('**/*',r=>new URL(r.request().url()).origin===new URL(base).origin?r.continue():r.abort());
  await context.addCookies([{name:'PALECOHUB',value:cookie,url:base+'/',httpOnly:true,sameSite:'Lax'}]);
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto(base+'/partial');
  const body=page.locator('#body'),title=page.locator('#title'),publish=page.locator('#partial-publish');
  assert.deepEqual(await page.locator('#announcement-form button').allTextContents(),['Publish','Cancel']);
  const text='PALECO INFO 11/03/26 11:07 AM\nPower Service Advisory Original Heading\nSupply restored in Maligaya.';
  const fill=async()=>{await body.fill(text);await page.waitForFunction(()=>document.querySelector('#title').value==='Power Service Advisory 11/03/26 11:07 AM');};
  const success=async()=>{await page.locator('#partial-success').waitFor({state:'visible'});assert.equal(await body.inputValue(),'');assert.equal(await title.inputValue(),'');assert.equal(await page.locator('[name=id]').inputValue(),'0');assert.equal(await page.locator('[name=confirmation_token]').inputValue(),'');assert.equal(await body.evaluate(el=>el===document.activeElement),true);assert.equal(page.url(),base+'/partial');};
  await fill();const op=await page.locator('[name=operation]').inputValue(),before=count();
  await publish.evaluate(el=>{el.click();el.click();});await success();await page.waitForTimeout(400);assert.equal(await title.inputValue(),'');assert.equal(await body.inputValue(),'');assert.equal(count(),before+1);assert.notEqual(await page.locator('[name=operation]').inputValue(),op);
  const first=Object.keys(state().posts);await fill();await publish.click();await success();assert.equal(count(),before+2);assert.equal(Object.keys(state().posts).length,first.length+1);
  await fill();const retryOp=await page.locator('[name=operation]').inputValue();mode('auth');await publish.click();await page.locator('#partial-error').waitFor({state:'visible'});assert.equal(await body.inputValue(),text);assert.equal(await page.locator('[name=operation]').inputValue(),retryOp);
  mode('post-timeout');await publish.click();await page.waitForFunction(()=>!document.querySelector('#partial-publish').disabled);assert.equal(await body.inputValue(),text);const accepted=count();await publish.click();await success();assert.equal(count(),accepted);
  // A draft/ambiguous JSON response is never enough to reset; retry identity survives.
  await fill();await page.route('**/partial/publish',r=>r.fulfill({json:{status:'wordpress_draft',wordpress_status:'draft',wordpress_post_id:999,history_finalized:true}}));await publish.click();await page.locator('#partial-error').waitFor({state:'visible'});assert.equal(await body.inputValue(),text);await page.unroute('**/partial/publish');
  await page.route('**/partial/publish',r=>r.abort('timedout'));await publish.click();await page.waitForFunction(()=>!document.querySelector('#partial-publish').disabled);assert.equal(await body.inputValue(),text);await page.unroute('**/partial/publish');
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));assert.deepEqual(errors,[]);
  console.log('BROWSER PASS: mobile reset/focus, next publication, fresh identity, double clicks, preserved failures/drafts/network timeouts and duplicate-safe reconciliation.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
