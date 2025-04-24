import { test, is, ok, run } from 'testra'
import { getServerWithFramework } from '../../tests/now/common.ts'

export default run(async () => {
  const { wpx, port } = await getServerWithFramework()
  test('Utility functions', async () => {
    let result

    const siteUrl = `http://localhost:${port}`

    const expectedPluginUrl = `${siteUrl}/wp-content/plugins/example-plugin`

    // UNIX-style file path (default) with forward slashes

    const unixStyleContentPath = `/var/www/html/wp-content`
    const unixStylePluginFilePath = `${unixStyleContentPath}/plugins/example-plugin/index.php`

    result = await wpx`
      return tangible\\framework\\module_url('${unixStylePluginFilePath}');
    `
    is(
      expectedPluginUrl,
      result,
      `framework\\module_url() returns plugin folder`,
    )

    const expectedModuleUrl = `${siteUrl}/wp-content/tangible/example-module`
    const unixStyleModuleFilePath = `${unixStyleContentPath}/tangible/example-module/index.php`

    result = await wpx`
    return tangible\\framework\\module_url('${unixStyleModuleFilePath}');
  `
    is(
      expectedModuleUrl,
      result,
      `framework\\module_url() returns module folder`,
    )

    // Windows-style file path with back slashes

    const windowsStyleContentPath = `C:\\laragon\\www\\algolia-general\\wp-content`
    const windowsStylePluginFilePath = `${windowsStyleContentPath}\\plugins\\example-plugin\\index.php`

    result = await wpx`
      define('WP_CONTENT_DIR_OVERRIDE', '${windowsStyleContentPath}');
      return tangible\\framework\\module_url('${windowsStylePluginFilePath}');
    `

    is(
      expectedPluginUrl,
      result,
      `framework\\module_url() returns plugin folder for Windows-style file path`,
    )

    const windowsStyleModuleFilePath = `${windowsStyleContentPath}\\tangible\\example-module\\index.php`

    result = await wpx`
      define('WP_CONTENT_DIR_OVERRIDE', '${windowsStyleContentPath}');
      return tangible\\framework\\module_url('${windowsStyleModuleFilePath}');
    `
    is(
      expectedModuleUrl,
      result,
      `framework\\module_url() returns module folder for Windows-style file path`,
    )
  })
})
