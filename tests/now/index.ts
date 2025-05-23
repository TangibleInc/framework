import url from 'node:url'
import path from 'node:path'
import fs from 'node:fs/promises'
import { test, is, ok, run } from 'testra'
import { getServer, ensureFrameworkActivated } from './common.js'

export default run(async () => {

  const { php, request, phpx, wpx, onMessage, console } = await getServer({
    phpVersion: process.env.PHP_VERSION || '8.2',
    reset: true,
  })

  test('Test site', async () => {
    ok(true, 'starts')

    let result = await request({
      route: '/',
      format: 'text',
    })

    ok(Boolean(result), 'responds')
    is('<!doc', result.slice(0, 5).toLowerCase(), 'responds with HTML document')

    // Activate Framework as plugin if needed
    result = await ensureFrameworkActivated({ wpx })

    result = await wpx/* php */ `
// Clear log
file_put_contents('wp-content/log.txt', '');
return get_option( 'permalink_structure' );`

    ok(true, 'PHP setup success')

    is('/%postname%/', result, 'pretty permalink enabled')

    result = await wpx/* php */ `return switch_theme('empty-block-theme');`
    is(null, result, 'activate empty block theme')
  })

  test('Post message from PHP to JS', async () => {
    let called = false
    let unsubscribe

    // Subscribe event callback
    unsubscribe = onMessage((e) => {
      called = e
    })

    const testPost = {
      post_id: 15,
      post_title: 'This is a blog post!',
    }

    await phpx`post_message_to_js(json_encode([
  ${Object.keys(testPost)
    .map((key) => `'${key}' => ${JSON.stringify(testPost[key])},`)
    .join('\n')}
]));`

    is(true, called !== false, 'listener called')
    is(testPost, called, 'listener called with JSON message')

    unsubscribe()

    called = false
    await phpx`post_message_to_js('1');`
    is(false, called, 'listener not called after unsubscribe')

    // New event callback
    let messages: any[] = []
    unsubscribe = onMessage((e) => {
      messages.push(e)
    })

    await wpx`test\\basic_messages();`

    is(
      [123, 'hi', { key: 'value' }],
      messages,
      'multiple messages from running PHP file',
    )
    // messages.splice(0)
    unsubscribe()

    // Support general-purpose assertions: [expected, actual, title]
    type AssertArgs = [expected: any, actual: any, title?: any]
    const asserts: AssertArgs[] = []
    unsubscribe = onMessage((e) => {
      asserts.push(e)
    })

    const prelude = /* php */`
function is($expected, $actual, $title = null) {
  post_message_to_js(json_encode([$expected, $actual, $title]));
}`

    await wpx`${prelude}
test\\basic_assertions();`
    unsubscribe()

    for (const [expected, actual, title] of asserts) {
      is(
        expected,
        actual,
        `assert from PHP: ${typeof title === 'string' ? title : JSON.stringify(title != null ? title : expected)}`,
      )
    }

    asserts.splice(0)
  })

  await import('../../api/tests/index.ts')
  await import('../../env/tests/index.ts')
  await import('../../file-system/tests/index.ts')
  await import('../../plugin/tests/index.ts')
  await import('../../utils/tests/index.ts')

  test('Log', async () => {
    const log = (
      await php.run({
        code: /* php */ `<?php
echo file_get_contents('wp-content/log.txt');
  `,
      })
    ).text

    if (log) console.log(log)
  })
})
