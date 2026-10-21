import { readFileSync, rmSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { pathToFileURL } from 'node:url'
import { compileScript, parse } from '@vue/compiler-sfc'
import { router } from '@inertiajs/vue3'
import ts from 'typescript'

const [file, state] = process.argv.slice(2)
const { descriptor } = parse(readFileSync(file, 'utf8'), { filename: file })
const { content } = compileScript(descriptor, { id: file, inlineTemplate: false })
const { outputText } = ts.transpileModule(content, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ESNext } })
const compiled = join(dirname(file), `.submit-${process.pid}.mjs`)
const sent = []

router.post = (url, data) => sent.push({ url, data })
writeFileSync(compiled, outputText)

try {
  const { default: component } = await import(pathToFileURL(compiled).href)
  const { form, submit } = component.setup({}, { expose: () => {} })

  Object.assign(form, JSON.parse(state))
  submit()
  console.log(JSON.stringify({ sent, errors: form.errors }))
} finally {
  rmSync(compiled)
}
