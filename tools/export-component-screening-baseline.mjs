import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { createHash } from 'node:crypto'
import { resolve } from 'node:path'
import vm from 'node:vm'

const root = resolve(import.meta.dirname, '..'), source = readFileSync(resolve(root, '../old/mdscr.js'), 'utf8')
const context = { window: {} }
vm.runInNewContext(source, context, { timeout: 1000 })
const current = JSON.parse(readFileSync(resolve(root, 'resources/catalogs/flow-screening-v2.json')))
const rules = new Map()
const assign = (rule, ids) => ids.forEach(id => rules.set(id, rule))
assign('material_carbon_low_alloy', ['acscc|1', 'aminescc|1', 'causticscc|1', 'hichf|1', 'hschf|1', 'hich2s|1', 'ssc|1', 'extf|1', 'cuif|1', 'bf|1'])
assign('material_austenitic_or_nickel', ['clscc|1', 'pascc|1'])
assign('material_austenitic', ['extcl|1', 'cuicl|1', 'sigma|1'])
assign('material_carbon', ['soil|2'])
assign('material_cr_mo', ['htha|1'])
assign('material_low_cr_mo', ['lase|1'])
assign('soil_contact', ['soil|1'])
assign('tank_floor', ['ast|1'])
assign('uninsulated', ['extf|3', 'extcl|2'])
assign('insulated', ['cuif|3', 'cuicl|2'])
assign('brittleness_temperature', ['bf|2'])
assign('pipeline', ['mfat|1'])
assign('vibration', ['mfat|2'])
const originals = new Map(context.window.__MDSCR.map(row => [row.id, row]))
const mechanisms = current.mechanisms.map(mechanism => {
  const original = originals.get(mechanism.id)
  const side = ['bf', 'lase', 'e885', 'sigma', 'mfat'].includes(original.id) ? 'through' : original.g === '2.D' || ['soil', 'ast'].includes(original.id) ? 'external' : original.q.some(q => q.c) ? 'process' : 'internal'
  return { ...mechanism, group: original.g, side_policy: side, questions: mechanism.questions.map(question => {
    let rule
    if (question.scope === 'flow') rule = 'flow'
    else if (question.conditions[0]?.field === 'T') rule = 'temperature'
    else if (question.conditions[0]?.field === 'P') rule = 'pressure'
    else if (question.conditions[0]?.field === 'Cr') rule = question.conditions[0].operator === '<' ? 'material_cr_co2' : 'material_cr_885'
    else rule = rules.get(question.id)
    if (!rule) throw Error('Map component question explicitly: ' + question.id)
    return { ...question, rule }
  }) }
})
const baseline = { version: 'component-screening-v1', source: { file: 'old/mdscr.js', sha256: createHash('sha256').update(source).digest('hex'), flow_schema_version: current.version }, mechanisms }
const file = resolve(root, 'resources/catalogs/component-screening-v1.json')
const serialized = JSON.stringify(baseline, null, 2) + '\n'
if (existsSync(file) && readFileSync(file, 'utf8') !== serialized) throw Error('Frozen component schema differs: create a new version and migration instead of overwriting v1')
writeFileSync(file, serialized)
console.log(JSON.stringify({ file, mechanisms: mechanisms.length, componentQuestions: mechanisms.flatMap(row => row.questions).filter(row => row.scope === 'component').length }))
