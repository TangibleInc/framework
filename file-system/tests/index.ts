import { test, is, ok, run } from 'testra'
import { getServer } from '../../env/index.js'
import { ensureFrameworkActivated } from '../../tests/common.js'

export default run(async () => {
  const { php, request, wpx } = await getServer()

  const namespace = 'tangible\\\\file_system'

  test('File system', async () => {
    let result: any
    let fn = (result = await ensureFrameworkActivated({ wpx }))
    if (!result) ok(false, 'Framework active')

    for (const method of [
      'instance',
      'read_file',
      'write_file',
      'is_writable',
      'mkdir',
      'rmdir',
      'is_dir',
      'dirlist',
      'move',
      'delete',
      'exists',
      'filename',
    ]) {
      result =
        await wpx/* php */ `return function_exists('${namespace}\\\\${method}');`

      is(true, result, `function ${method}() exists`)
    }

    // TODO: Actually test file operations
  })
})
