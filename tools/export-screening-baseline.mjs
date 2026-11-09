import { readFileSync, writeFileSync } from 'node:fs'
import vm from 'node:vm'
const context = { window: {} }
vm.runInNewContext(readFileSync('../frontend/src/domain/calculations/mdscr.js', 'utf8'), context, { timeout: 1000 })
const { __MDF: fields, __MDSCR: mechanisms } = context.window
const nums = s => [...String(s ?? '').replace(/(\d),(\d)/g, '$1.$2').matchAll(/-?\d+(?:\.\d+)?/g)].map(m => Number(m[0]))
const baseline = { version: 'flow-screening-v1', source: 'mdscr.js; правила проекта 14–16.10.2026', fields: fields.map(f => ({ id: f.id, label: f.ru, english_label: f.en, type: f.t === 'b' ? 'boolean' : 'number', unit: f.u ?? '', min: 0, max: f.id === 'ph' ? 14 : f.id === 'naoh' ? 100 : 1e12 })), mechanisms: mechanisms.map(m => ({ id: m.id, label: m.ru, english_label: m.en, source: `API 581, 4-е изд., табл. ${m.tbl}, с. ${m.pg}`, damage_mechanisms: m.dm, component_only: m.co, questions: m.q.map(q => ({ id: `${m.id}|${q.k}`, label: q.ru, english_label: q.en, scope: q.c ? 'flow' : 'component', conditions: (q.c ?? (q.n ? [q.n] : [])).map(c => ({ field: c.f, operator: c.op ?? 'yes', threshold: nums(c.thr), near: nums(c.near) })) })) })) }
writeFileSync('resources/catalogs/flow-screening.json', JSON.stringify(baseline, null, 2)+'\n')
console.log({ fields: fields.length, mechanisms: mechanisms.length, flowMechanisms: mechanisms.filter(m=>!m.co).length, questions: baseline.mechanisms.flatMap(m=>m.questions).length })
