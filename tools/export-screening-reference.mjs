import fs from 'node:fs'
import vm from 'node:vm'
const { window: w } = vm.createContext({ window: {} })
const context = { window: w }
vm.runInNewContext(fs.readFileSync('../frontend/src/domain/calculations/mdscr.js', 'utf8'), context, { timeout: 1000 })
const cases = []
let seed = 98431
const random = () => (seed = (Math.imul(seed, 1664525) + 1013904223) >>> 0) / 2 ** 32
for (let i = 0; i < 24; i++) {
 const values = {}, legacy = {}
 for (const f of w.__MDF) {
  const pick = i < 3 ? i : Math.floor(random()*5)
  const value = f.t === 'b' ? [null, true, false, true, null][pick] : [null, 0, 7, .345, 50][pick]
  if(value !== null) { values[f.id]=value; legacy[f.id] = f.t === 'b' ? value ? 'да' : 'нет' : value }
 }
 const thresholds = i % 2 ? { 'hcl|3': { threshold:[7], near:[.3] } } : {}
 const expected = {}
 for (const m of w.__MDSCR.filter(m=>!m.co)) {
  const statuses = m.q.filter(q=>q.c).flatMap(q=>q.c.map(c=>w.__mdEng.flowCond(c,`${m.id}|${q.k}`,{md:{v:legacy,thr: i % 2 ? {'hcl|3':{thr:'7',near:'0.3'}} : {}}},{}).r))
  expected[m.id] = statuses.includes('n') ? 'inactive' : statuses.some(s=>s==='u'||s==='p') ? 'potential' : 'active'
 }
 cases.push({values, thresholds, expected})
}
fs.writeFileSync('tests/Fixtures/flow-screening-reference.json', JSON.stringify(cases,null,2)+'\n')
console.log('Exported 24 reference cases, 504 mechanism results from the existing JS engine.')
