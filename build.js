import path from 'node:path'
import fs from 'node:fs/promises'
import esbuild from 'esbuild'
import { globby } from 'globby'
import { transformExtPlugin } from '@gjsify/esbuild-plugin-transform-ext'

const args = process.argv.slice(2)
let command = args.shift() || 'build'
const isDev = command === 'dev'

if (isDev) command = args.shift() // Optional: cjs, esm, web

async function fileExists(file) {
  try {
    await fs.access(file)
    return true
  } catch (e) {
    return false
  }
}

;(async () => {
  // import { version } from './package.json'

  const esbuildOptions = {
    logLevel: 'info',
    jsx: 'automatic',
    tsconfig: 'env/tsconfig.common.json',
    plugins: [
      // Built ES module format expects import from .js
      transformExtPlugin({ outExtension: { '.ts': '.js' } }),
    ],

    platform: 'node',
    bundle: false,
    minify: false,
    sourcemap: false,
  }

  const globCommonOptions = {
    ignore: ['**/*.spec.ts', '**/*.d.ts'],
  }

  // For simplicity just compile to ES Module only, no CommonJS

  for (const { name, ...options } of [
    {
      name: 'env',
      entryPoints: [`**/*.{ts,js}`],
      includeFiles: [
        `e2e-plugin/**/*`,
        'package.json',
        'readme.md',
        'tsconfig*.json'
      ],
      ignore: []
    },
  ]) {
    const globOptions = {
      ...globCommonOptions,
      cwd: name, // Set to current folder to remove from file path
      ignore: [
        ...globCommonOptions.ignore,
        ...(options.ignore || [])
      ]
    }
    const entryPoints = await globby(options.entryPoints, globOptions)
    const includeFiles = options.includeFiles
      ? await globby(options.includeFiles, globOptions)
      : []

    const srcDir = name
    const destDir = `./build/${name}`
    await fs.mkdir(destDir, { recursive: true })

    console.log('Build', entryPoints)

    const context = await esbuild.context({
      ...esbuildOptions,
      entryPoints: entryPoints.map((file) => `${srcDir}/${file}`),
      outdir: destDir,
      format: 'esm',
    })
    await context.rebuild()

    for (const file of includeFiles) {
      console.log('Copy', file)
      const srcFile = `${srcDir}/${file}`
      const destFile = `${destDir}/${file}`

      // Create any sub directories of file
      const dir = path.dirname(destFile)
      await fs.mkdir(dir, { recursive: true })

      await fs.copyFile(srcFile, destFile)
    }
  }

  if (isDev) {
    // await context.watch()
  } else {
    process.exit()
  }
})().catch((error) => {
  console.error(error)
  process.exit(1)
})
