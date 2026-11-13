import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitepress'

// The website is built straight from docs/: the numbered pages are the chapters,
// in order. They stay readable on GitHub, so this file only adapts them for the
// site: clean URLs, a generated sidebar, links out of docs/ pointing at GitHub,
// and no "← previous · Contents · next →" footers (the site has its own).

const repository = 'https://github.com/pyhp-php/pyhp'
const docs = dirname(dirname(fileURLToPath(import.meta.url)))

/** The chapters: 01-introduction.md → { file, slug: 'introduction', title: 'Introduction' } */
const chapters = readdirSync(docs)
  .filter((file) => /^\d{2}-.+\.md$/.test(file))
  .sort()
  .map((file) => {
    const heading = readFileSync(join(docs, file), 'utf8').match(/^# (?:\d+\.\s*)?(.+)$/m)
    return { file, slug: file.slice(3, -3), title: heading ? heading[1].trim() : file }
  })

export default defineConfig({
  title: 'PyHP',
  description: 'Use Python libraries from PHP: pandas, NumPy, scikit-learn and the rest, through proxies.',
  // GitHub Pages serves the site under the repository's name, and that path is
  // case-sensitive (PyHP, not pyhp). CI passes the exact path Pages reports.
  base: process.env.DOCS_BASE || '/PyHP/',
  cleanUrls: true,
  srcExclude: ['README.md', 'tasks/**'],
  rewrites: Object.fromEntries(chapters.map(({ file, slug }) => [file, `${slug}.md`])),
  head: [['meta', { name: 'theme-color', content: '#3776ab' }]],

  markdown: {
    config(md) {
      // Links that leave docs/ (../CHANGELOG.md, ../benchmarks/..., tasks/...) go to GitHub;
      // links to the contents page (README.md) go to the home page.
      md.core.ruler.push('pyhp_links', (state) => {
        for (const token of state.tokens) {
          for (const child of token.children ?? []) {
            if (child.type !== 'link_open') continue
            const href = child.attrGet('href') ?? ''
            if (href.startsWith('../')) child.attrSet('href', `${repository}/blob/main/${href.slice(3)}`)
            else if (href.startsWith('tasks/')) child.attrSet('href', `${repository}/blob/main/docs/${href}`)
            else if (href === 'README.md') child.attrSet('href', '/')
            // Chapters are served without their number (01-introduction.md → /introduction),
            // so links between them must drop it too; VitePress doesn't rewrite them itself.
            else if (/^(\.\/)?\d{2}-[^/]+\.md(#.*)?$/.test(href)) child.attrSet('href', href.replace(/^(\.\/)?\d{2}-/, './'))
          }
        }
      })
      // Drop the closing "---" and "← previous · Contents · next →" line of each chapter.
      md.core.ruler.push('pyhp_footer', (state) => {
        const tokens = state.tokens
        const n = tokens.length
        if (n >= 4 && tokens[n - 4].type === 'hr' && tokens[n - 2].type === 'inline'
          && tokens[n - 2].content.includes('[Contents](README.md)')) {
          tokens.splice(n - 4, 4)
        }
      })
    },
  },

  themeConfig: {
    nav: [
      { text: 'Guide', link: '/introduction' },
      { text: 'API', link: '/api-reference' },
      { text: 'Benchmarks', link: '/memory-and-performance#performance' },
      { text: 'Upgrading', link: '/upgrading' },
      { text: 'Changelog', link: `${repository}/blob/main/CHANGELOG.md` },
    ],
    sidebar: [
      { text: 'Documentation', items: chapters.map(({ slug, title }) => ({ text: title, link: `/${slug}` })) },
    ],
    socialLinks: [{ icon: 'github', link: repository }],
    search: { provider: 'local' },
    editLink: { pattern: `${repository}/edit/develop/docs/:path`, text: 'Edit this page on GitHub' },
    outline: { level: [2, 3] },
    footer: { message: 'Released under the MIT License.' },
  },
})
