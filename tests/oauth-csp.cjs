// Browser regression for the real form -> local POST -> Google redirect chain.
// No credentials, CSRF tokens, OAuth states or PKCE values are logged.
const {chromium}=require('../storage/qa/node_modules/playwright');
const assert=require('node:assert/strict');
const base=process.env.HUB_TEST_URL||'http://localhost/paleco-hub';
const expectBlocked=process.argv.includes('--expect-blocked');
const safeUrl=value=>{const u=new URL(value);return u.origin+u.pathname;};
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try {
  const context=await browser.newContext();const page=await context.newPage();
  await page.addInitScript(()=>{window.cspViolations=[];document.addEventListener('securitypolicyviolation',event=>window.cspViolations.push({directive:event.effectiveDirective,blocked:event.blockedURI}));});
  const response=await page.goto(base+'/');
  const policies=(await response.headersArray()).filter(h=>/content-security-policy/i.test(h.name));
  console.log('Login CSP headers:',JSON.stringify(policies));assert.equal(policies.length,1);
  assert.equal(await page.locator('meta[http-equiv="Content-Security-Policy" i]').count(),0);
  const action=await page.locator('form').evaluate(form=>form.action);console.log('Rendered form action:',action);
  assert.equal(action,base+'/auth/google');assert.equal(new URL(action).origin,new URL(page.url()).origin);
  await page.locator('[name=remember]').check();
  // Inspect the redirect without following it; Chromium can suppress its response event when CSP blocks it.
  const csrf=await page.locator('[name=_token]').inputValue();
  const redirect=await context.request.post(action,{form:{_token:csrf,remember:'1'},maxRedirects:0});assert.equal(redirect.status(),302);
  const headers=redirect.headers();const location=new URL(headers.location);
  console.log('POST response:',redirect.status(),'Location:',safeUrl(location.href));
  console.log('POST CSP headers:',JSON.stringify(redirect.headersArray().filter(h=>/content-security-policy/i.test(h.name))));
  assert.equal(location.origin,'https://accounts.google.com');assert.equal(location.pathname,'/o/oauth2/v2/auth');
  assert.equal(location.searchParams.get('redirect_uri'),base+'/auth/google/callback');
  assert.ok(location.searchParams.get('state'));assert.ok(location.searchParams.get('code_challenge'));assert.equal(location.searchParams.get('code_challenge_method'),'S256');
  const postRequest=page.waitForRequest(r=>r.url()===action&&r.method()==='POST');
  await page.getByRole('button',{name:'Continue with Google'}).click({noWaitAfter:true});
  const sent=new URLSearchParams((await postRequest).postData());assert.ok(sent.get('_token'));assert.equal(sent.get('remember'),'1');
  if(expectBlocked){await page.waitForFunction(()=>window.cspViolations?.some(v=>v.directive==='form-action'));const violations=await page.evaluate(()=>window.cspViolations);console.log('Reproduced blocked form-action redirect:',violations.map(v=>({directive:v.directive,blocked:safeUrl(v.blocked)})));}
  else {
   await page.waitForURL(u=>u.origin==='https://accounts.google.com',{waitUntil:'domcontentloaded',timeout:45000});
   console.log('Google authorization page reached:',safeUrl(page.url()));
   // Back in Hub, check an unrelated external form is still rejected by CSP.
   await page.goto(base+'/');
   await page.evaluate(()=>{const f=document.createElement('form');f.method='POST';f.action='https://example.invalid/blocked';document.body.appendChild(f);f.submit();});
   await page.waitForFunction(()=>window.cspViolations?.some(v=>v.directive==='form-action'));
   assert.equal(new URL(page.url()).origin,new URL(base).origin);
   console.log('Unrelated external form destination remains blocked.');
  }
  console.log('OAuth state, S256 PKCE, CSRF and Remember Me submission verified.');
 } finally {await browser.close();}
})().catch(error=>{console.error(error.message);process.exit(1);});
