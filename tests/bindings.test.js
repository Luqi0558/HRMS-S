import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'

/**
 * Catches store helpers that a component calls but never destructured.
 *
 * `vite build` compiles these happily and the smoke tests mount the page
 * happily — the ReferenceError only fires when somebody clicks the thing that
 * uses it. Two shipped that way: `canSignOffTickets` broke opening a ticket on
 * the project board, and `prependMessages` would have broken loading older
 * messages. Both were introduced by string edits that silently matched nothing.
 */
describe('store bindings', () => {
  // cwd, not a URL: vitest runs from the project root, and file:// pathnames
  // come back with a leading slash before the drive letter on Windows.
  const ROOT = process.cwd()

  /** Names the store returns from useStore(). */
  function storeExports() {
    const source = readFileSync(join(ROOT, 'src/store/index.js'), 'utf8')
    const tail = source.slice(source.lastIndexOf('export function useStore'))

    return new Set([...tail.matchAll(/^\s{4}(\w+),\s*$/gm)].map(match => match[1]))
  }

  function vueFiles() {
    const dirs = ['src/views', 'src/components']
    return dirs.flatMap(dir =>
      readdirSync(join(ROOT, dir))
        .filter(name => name.endsWith('.vue'))
        .map(name => join(dir, name))
    )
  }

  const exported = storeExports()

  it('finds the store exports', () => {
    // Guards the test itself: a parsing change that returned nothing would make
    // every assertion below vacuously pass.
    expect(exported.size).toBeGreaterThan(20)
  })

  for (const file of vueFiles()) {
    it(`${file.split(/[\\/]/).pop()} destructures everything it calls`, () => {
      const source = readFileSync(join(ROOT, file), 'utf8')
      const composable = source.match(/=\s*use(?:Store|Dashboard)\(\)/)
      if (!composable) return

      // Every identifier inside the braces, closing brace included. Matching on
      // a following comma or newline missed the last entry of a single-line
      // destructure (`const { state, can, logActivity }`) and reported it as
      // undefined.
      const head = source.slice(0, composable.index)
      const block = head.slice(head.lastIndexOf('{'), head.lastIndexOf('}') + 1)
      const destructured = new Set(block.match(/\w+/g) || [])

      // Comments stripped first: Login.vue mentions `can()` in a note about
      // the router guard, which is not a call and must not be reported as one.
      const code = source
        .replace(/\/\*[\s\S]*?\*\//g, ' ')
        .replace(/^\s*\/\/.*$/gm, ' ')
        .replace(/<!--[\s\S]*?-->/g, ' ')

      const missing = [...exported].filter(name => {
        // Called as a function somewhere in the file...
        const called = new RegExp(`(?<![.\\w])${name}\\s*\\(`).test(code)
        // ...and neither destructured nor defined locally.
        const local = new RegExp(`(?:function|const|let)\\s+${name}\\b`).test(code)
        return called && !destructured.has(name) && !local
      })

      expect(missing, `${file} calls these without destructuring them: ${missing.join(', ')}`).toEqual([])
    })
  }

  /**
   * Composables reach the store through a `store` object plus a small
   * destructure, so a bare `myModules()` there compiles and then throws when
   * somebody clicks. That is how a dashboard tile shipped broken after the
   * .vue files were already covered by the checks above.
   */
  const COMPOSABLES = readdirSync(join(ROOT, 'src/composables'))
    .filter(name => name.endsWith('.js'))
    .map(name => join('src/composables', name))

  for (const file of COMPOSABLES) {
    it(`${file.split(/[\/]/).pop()} reaches store helpers through the store`, () => {
      const source = readFileSync(join(ROOT, file), 'utf8')
      if (!/useStore\(\)/.test(source)) return

      const code = source
        .replace(/\/\*[\s\S]*?\*\//g, ' ')
        .replace(/^\s*\/\/.*$/gm, ' ')

      // Names pulled out of the store by destructuring are callable bare.
      const destructured = new Set(
        [...code.matchAll(/(?:const|let)\s*\{([^}]*)\}\s*=\s*(?:store|useStore\(\))/g)]
          .flatMap(match => match[1].match(/\w+/g) || [])
      )

      const missing = [...exported].filter(name => {
        const bare = new RegExp(`(?<![.\\w])${name}\\s*\\(`).test(code)
        const local = new RegExp(`(?:function|const|let)\\s+${name}\\b`).test(code)
        return bare && !destructured.has(name) && !local
      })

      expect(missing, `${file} calls these bare instead of via store.: ${missing.join(', ')}`).toEqual([])
    })
  }
})
