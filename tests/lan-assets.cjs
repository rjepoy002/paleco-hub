const {chromium}=require('../storage/qa/node_modules/playwright');
const assert=require('node:assert/strict');const path=require('node:path');
const broken=process.argv.includes('--expect-broken');
const bases=process.argv.filter(v=>/^https?:\/\//.test(v));if(!bases.length)bases.push('http://localhost/paleco-hub','http://192.168.20.94/paleco-hub');
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try{
 for(const base of bases){
  const context=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true});
  const page=await context.newPage();const errors=[],violations=[],failures=[],requests=[];
  page.on('pageerror',e=>errors.push(e.message));page.on('requestfailed',r=>failures.push(new URL(r.url()).pathname));page.on('request',r=>requests.push(r.url()));
  await page.addInitScript(()=>{window.assetCsp=[];document.addEventListener('securitypolicyviolation',e=>window.assetCsp.push(e.effectiveDirective));});
  const response=await page.goto(base+'/');
  const assets=await page.locator('link[rel=stylesheet],script[src],img[src],link[rel=icon],link[rel=apple-touch-icon],link[rel=manifest]').evaluateAll(nodes=>nodes.map(n=>n.src||n.href));
  const origin=new URL(base).origin;
  console.log('Page:',base,'asset origins:',[...new Set(assets.map(v=>new URL(v).origin))]);
  if(broken){assert.ok(assets.some(v=>new URL(v).origin!==origin));console.log('Reproduced wrong-origin assets and CSP directives:',await page.evaluate(()=>window.assetCsp));await context.close();continue;}
  assert.equal(response.status(),200);assert.ok(assets.every(v=>new URL(v).origin===origin));
  assert.equal((await response.headersArray()).filter(h=>h.name.toLowerCase()==='content-security-policy').length,1);
  assert.equal(await page.locator('meta[http-equiv="Content-Security-Policy" i]').count(),0);
  assert.ok((await page.locator('meta[name=viewport]').getAttribute('content')).includes('width=device-width'));
  for(const asset of assets){const r=await context.request.get(asset);assert.equal(r.status(),200,asset);const ext=new URL(asset).pathname.split('.').pop();const expected={css:'text/css',js:'javascript',png:'image/png',json:'json'}[ext];assert.ok((r.headers()['content-type']||'').includes(expected),asset);console.log('ASSET PASS:',new URL(asset).pathname,r.status(),r.headers()['content-type']);}
  for(const width of [360,390,430,1280]){
   await page.setViewportSize({width,height:844});
   assert.ok(await page.locator('.login-logo').evaluate(img=>img.complete&&img.naturalWidth>0));
   assert.equal(await page.locator('.login-card').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(255, 255, 255)');
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
   const button=await page.getByRole('button',{name:'Continue with Google'}).boundingBox();assert.ok(button.height>=44);
   const remember=await page.locator('.remember-label').boundingBox();assert.ok(remember.height>=44);
   await page.locator('[name=remember]').check();assert.ok(await page.locator('[name=remember]').isChecked());
   await page.locator('[name=remember]').uncheck();
   if(width===390)await page.screenshot({path:path.join(__dirname,`../storage/screenshots/login-${origin.includes('localhost')?'localhost':'lan'}-390.png`),fullPage:true});
   console.log('LAYOUT PASS:',origin,width,'px; styled, no overflow, touch controls usable');
  }
  violations.push(...await page.evaluate(()=>window.assetCsp));assert.deepEqual(violations,[]);assert.deepEqual(errors,[]);assert.deepEqual(failures,[]);
  assert.ok(requests.every(v=>new URL(v).origin===origin),'no wrong-host or mixed-content requests');
  const action=await page.locator('form').evaluate(form=>form.action);assert.equal(new URL(action).origin,origin);
  if(!origin.includes('localhost')){
   const token=await page.locator('[name=_token]').inputValue();
   const result=await context.request.post(action,{form:{_token:token,remember:'1'},maxRedirects:0});
   assert.equal(result.status(),302);assert.equal(result.headers().location,'/paleco-hub/login');
   await page.goto(base+'/login');assert.ok((await page.locator('.login-page').textContent()).includes('configured OAuth origin'));
   console.log('OAUTH PASS: mismatched LAN origin is explained locally; no callback rewrite or auth bypass.');
  }
  await context.close();
 }
 }finally{await browser.close();}
})().catch(e=>{console.error(e.message);process.exit(1);});
