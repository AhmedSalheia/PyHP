// Run after `vitepress build`: fails if a page still links to a chapter by its
// numbered file name (./02-installation), which the site doesn't serve. VitePress's
// own dead-link check misses these, because the numbered source files exist.
import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'

const dist = new URL('./dist/', import.meta.url).pathname
const broken = []
for (const file of readdirSync(dist).filter((name) => name.endsWith('.html'))) {
  for (const [, href] of readFileSync(join(dist, file), 'utf8').matchAll(/href="([^"]*)"/g)) {
    if (!/^https?:/.test(href) && /(^|\/)\d{2}-[^/]+$/.test(href.split('#')[0])) broken.push(`${file}: ${href}`)
  }
}
if (broken.length > 0) {
  console.error(`Links to numbered chapter files, which the site doesn't serve:\n  ${broken.join('\n  ')}`)
  process.exit(1)
}
console.log('No links to numbered chapter files.')
