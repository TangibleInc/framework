/**
 * Update version of all published packages
 */
import fs from 'node:fs/promises'
;(async () => {
  const version = new Date().toISOString().slice(0, 10).replace(/-/g, '')
  const versionWithDots =
    version.slice(0, 4) + '.' + version.slice(4, 6) + '.' + version.slice(6, 8)
  console.log('Version', versionWithDots)

  // Version number with dots should have no zero padding 1.02.03 -> 1.2.3
  const versionWithDotsNoZeroPadding = versionWithDots.split('.').map(i => parseInt(i, 10).toString()).join('.')

  for (const file of [
    'index.php',
    'date/index.php',
    'plugin.php',
    'package.json',
    'env/package.json'
  ]) {
    console.log('Update', file)

    // YYYYMMDD

    const content = (await fs.readFile(file, 'utf8'))
      .replace(/return '[0-9]{8}'/, `return '${version}'`)
      .replace(/'version' => '[0-9]{8}'/, `'version' => '${version}'`)
      .replace(
        /'version' => '[0-9]{4}\.[0-9]+\.[0-9]+'/,
        `'version' => '${versionWithDotsNoZeroPadding}'`,
      )
      .replace(/\$version = '[0-9]{8}'/, `$version = '${version}'`)
      .replace(
        /"version": "[0-9]{4}\.[0-9]+\.[0-9]+"/,
        `"version": "${versionWithDotsNoZeroPadding}"`,
      )
      .replace(
        /Version: [0-9]{4}\.[0-9]+\.[0-9]+/,
        `Version: ${versionWithDotsNoZeroPadding}`,
      )

    // console.log(content)

    await fs.writeFile(file, content)
  }
})().catch(console.error)
