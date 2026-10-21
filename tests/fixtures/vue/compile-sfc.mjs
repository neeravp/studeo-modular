import { readFileSync } from 'node:fs'
import { basename } from 'node:path'
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc'

const compile = (file) => {
  const { descriptor, errors } = parse(readFileSync(file, 'utf8'), { filename: file })
  const problems = errors.map(String)
  const isTS = descriptor.scriptSetup?.lang === 'ts' || descriptor.script?.lang === 'ts'
  let bindings

  try {
    bindings = descriptor.scriptSetup || descriptor.script ? compileScript(descriptor, { id: file }).bindings : undefined
  } catch (error) {
    problems.push(String(error))
  }

  const template = descriptor.template
    ? compileTemplate({ source: descriptor.template.content, filename: file, id: file, compilerOptions: { bindingMetadata: bindings, isTS } })
    : { errors: [] }

  return [...problems, ...template.errors.map(String)]
}

const results = Object.fromEntries(process.argv.slice(2).map((file) => [basename(file), compile(file)]))

console.log(JSON.stringify(results))
process.exitCode = Object.values(results).some((problems) => problems.length > 0) ? 1 : 0
