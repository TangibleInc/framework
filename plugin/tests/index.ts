import { test, is, ok, run } from 'testra'
import { getServerWithFramework } from '../../tests/common.ts'

export default run(async () => {
  const { php, request, wpx } = await getServerWithFramework()

  test('Plugin settings', async () => {
    let result

    const registerPlugin = `$plugin = tangible\\framework\\register_plugin([
      'name' => 'example'
    ]);`;

    result = await wpx`
      ${registerPlugin}
      return $plugin;
    `
    is(true, typeof result==='object', `register_plugin() returns object`)
    is(true, 'name' in result, `$plugin->name`)

    result = await wpx`
      ${registerPlugin}
      return tangible\\framework\\get_plugin_settings_page_config( $plugin );
    `
    is(true, typeof result==='object', `get_plugin_settings_page_config() returns object`)
    is(true, 'slug' in result, `settings page slug`)
    is(true, 'url' in result, `settings page url`)
    is(true, 'url_base' in result, `settings page url base`)

    // Double the backslash to escape in JS template literal then PHP string
    result = await wpx`return function_exists('tangible\\\\framework\\\\is_plugin_settings_page');`

    is(true, result, `function is_plugin_settings_page() exists`)

    result = await wpx`
      ${registerPlugin}
      return tangible\\framework\\is_plugin_settings_page($plugin);
    `

    is(false, result, `is not plugin settings page`)

    result = await wpx`
      use tangible\\framework;
      ${registerPlugin}
      global $pagenow;
      $pagenow = framework\\get_plugin_settings_page_url_base($plugin);
      $_GET['page'] = framework\\get_plugin_settings_page_slug($plugin);
      return framework\\is_plugin_settings_page($plugin);
    `

    is(true, result, `is plugin settings page`)

  })
})
