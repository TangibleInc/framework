
const silentConsole = {
  log() {},
  warn() {},
  error() {},
}
export const originalConsole = globalThis.console

export const disableConsole = () => {
  // Silence console messages from NodePHP
  globalThis.console = silentConsole as Console
}

export const enableConsole = () => {
  globalThis.console = originalConsole
}
