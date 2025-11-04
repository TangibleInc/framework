import { test, is, ok, run } from 'testra'
import { getServer } from '../../../tests/now/common.ts'

export default run(async () => {
  const { php, request, phpx, wpx, onMessage, console, documentRoot, siteUrl } =
    await getServer({
      phpVersion: process.env.PHP_VERSION || '8.2',
      reset: true,
    })

  test('Test plugin', async () => {
    const testPluginDir = `${documentRoot}/wp-content/plugins/test`
    const testPluginFile = `${testPluginDir}/test.php`
    if (!(await php.fileExists(testPluginDir))) {
      await php.mkdir(testPluginDir)
    }

    await php.writeFile(
      testPluginFile,
      `<?php
/**
* Plugin Name: Test
*/
add_action('wp', function() {
  wp_set_current_user(0); // Login as admin
});
`,
    )

    ok(true, 'create test plugin')

    let result = await wpx/* php */ `
if (!function_exists('activate_plugin')) {
require ABSPATH . 'wp-admin/includes/plugin.php';
}
$result = activate_plugin(ABSPATH . 'wp-content/plugins/test/test.php');
return !is_wp_error($result);
`

    ok(true, 'activate test plugin')

    result = await fetch(`${siteUrl}`, {
      credentials: 'same-origin',
    })
    is(200, result.status, 'route / responds with status OK')
    // console.log(result)

    result = await fetch(`${siteUrl}/wp-admin`, {
      credentials: 'same-origin',
    })
    is(200, result.status, 'route /wp-admin responds with status OK')
    // console.log(result.url)

    // TODO: Pass cookie to subsequent fetch requests to stay logged in
  })
})
