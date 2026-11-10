import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import vm from 'node:vm'
import { resolve } from 'node:path'
const root = resolve(import.meta.dirname, '..')
const context = { window: {} }
vm.runInNewContext(readFileSync(resolve(root, '../old/mdscr.js'), 'utf8'), context, { timeout: 1000 })
const { __MDF: fields, __MDSCR: mechanisms } = context.window
const nums = s => [...String(s ?? '').replace(/−/g, '-').replace(/(\d),(\d)/g, '$1.$2').matchAll(/-?\d+(?:\.\d+)?/g)].map(m => Number(m[0]))
const baseline = { version: 'flow-screening-v2', source: 'mdscr.js; правила проекта 14–16.10.2026', fields: fields.map(f => ({ id: f.id, label: f.ru, english_label: f.en, type: f.t === 'b' ? 'boolean' : 'number', unit: f.u ?? '', min: 0, max: f.id === 'ph' ? 14 : f.id === 'naoh' ? 100 : 1e12 })), mechanisms: mechanisms.map(m => ({ id: m.id, label: m.ru, english_label: m.en, source: `API 581, 4-е изд., табл. ${m.tbl}, с. ${m.pg}`, damage_mechanisms: m.dm, component_only: m.co, questions: m.q.map(q => ({ id: `${m.id}|${q.k}`, label: q.ru, english_label: q.en, scope: q.c ? 'flow' : 'component', conditions: (q.c ?? (q.n ? [q.n] : [])).map(c => ({ field: c.f, operator: c.op ?? 'yes', threshold: nums(c.thr), near: nums(c.near) })) })) })) }
const file = resolve(root, 'resources/catalogs/flow-screening-v2.json'), serialized = JSON.stringify(baseline, null, 2) + '\n'
if (existsSync(file) && readFileSync(file, 'utf8') !== serialized) throw Error('Frozen runtime screening schema differs: create a new version instead of overwriting v2')
writeFileSync(file, serialized)
console.log({ fields: fields.length, mechanisms: mechanisms.length, flowMechanisms: mechanisms.filter(m=>!m.co).length, questions: baseline.mechanisms.flatMap(m=>m.questions).length })
