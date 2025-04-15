import path from 'node:path'
import { fileURLToPath } from 'url'
import { createEnvConfig } from './playwright/config.js'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

export default createEnvConfig({
  testDir: path.join(__dirname, 'tests'),
  testMatch: '**/*.js'
})
