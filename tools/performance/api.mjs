import fs from 'node:fs';
import assert from 'node:assert/strict';
import {chromium} from '../../../frontend/node_modules/playwright/index.mjs';
import {expect} from '../../../frontend/node_modules/playwright/test.mjs';

const account=fs.readFileSync(new URL('../../.tools/local-account.txt',import.meta.url),'utf8');
const email=account.match(/^Email:\s*(.+)$/mi)?.[1]?.trim(),password=account.match(/^Password:\s*(.+)$/mi)?.[1]?.trim();
if(!email||!password)throw Error('Verification credentials are unavailable');
const browser=await chromium.launch({channel:'msedge',headless:true,args:['--no-proxy-server']});
const context=await browser.newContext(),page=await context.newPage();
const samples={};const summary={};
const percentile=(values,p)=>[...values].sort((a,b)=>a-b)[Math.ceil(values.length*p)-1];
try {
 await page.goto('http://localhost:5173');
 await page.getByLabel('E-mail',{exact:true}).fill(email);
 await page.getByLabel('Пароль',{exact:true}).fill(password);
 await page.getByRole('button',{name:'Войти',exact:true}).click();
 await expect(page.locator('.auth-card')).toHaveCount(0,{timeout:30000});
 await page.waitForLoadState('networkidle');
 const paths=['/up','/api/equipment/navigation','/api/equipment','/api/equipment/b479addb-44e2-5a0e-8ffa-11db91c760c7'];
 const urls={windows:'http://localhost:8080',linux:'http://localhost:18081'};
 for(const path of paths){
  const bodies={};samples[path]={windows:[],linux:[]};
  for(let round=0;round<17;round++){
   for(const mode of round%2?['linux','windows']:['windows','linux']){
    const start=performance.now();
    const response=await context.request.get(urls[mode]+path,{headers:{Accept:'application/json',Origin:'http://localhost:5173','X-Tenant':'rline'},timeout:30000});
    const body=await response.text();const ms=performance.now()-start;
    assert.equal(response.status(),200,mode+' '+path+' '+body.slice(0,100));
    if(path!=='/up')bodies[mode]=JSON.parse(JSON.stringify(JSON.parse(body)).replaceAll(urls[mode],"http://benchmark.local"));
    if(round>1)samples[path][mode].push(Number(ms.toFixed(2)));
   }
  }
  if(path!=='/up')assert.deepEqual(bodies.windows,bodies.linux,'API response mismatch '+path);
  summary[path]=Object.fromEntries(Object.entries(samples[path]).map(([mode,values])=>[mode,{median_ms:percentile(values,.5),p95_ms:percentile(values,.95),n:values.length}]));
  summary[path].speedup=Number((summary[path].windows.median_ms/summary[path].linux.median_ms).toFixed(2));
  console.log(JSON.stringify({path,...summary[path]}));
 }
 const output={summary,samples,method:'Sequential alternating Windows/Linux, same PHP image/vendor/storage/DB, 2 warmups then 15 samples, host HTTP round trip with full body, JSON equality after normalizing only the origin in pagination links.'};
 fs.writeFileSync(new URL('../../.tools/io-api-results.json',import.meta.url),JSON.stringify(output,null,2));
}finally{await browser.close();}