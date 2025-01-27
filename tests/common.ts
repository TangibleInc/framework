import { getServer } from '../env/index.js'

export async function getServerWithFramework() {
  const server = await getServer({
    phpVersion: process.env.PHP_VERSION || '8.2',
    reset: true,
  })
  const { wpx } = server
  await ensureFrameworkActivated({ wpx })
  return server
}

// Activate Framework as plugin if needed
export async function ensureFrameworkActivated({ wpx }) {
  return await wpx/* php */ `
if (class_exists('tangible\\framework')) return true;
if (!function_exists('activate_plugin')) {
  require ABSPATH . 'wp-admin/includes/plugin.php';
}
$result = activate_plugin(ABSPATH . 'wp-content/plugins/tangible-framework/plugin.php');

return !is_wp_error($result);`
}
