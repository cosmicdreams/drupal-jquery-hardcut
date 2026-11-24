/**
 * @file
 * Warns when a script reads a missing jQuery global.
 */

(() => {
  const canaryUrl = document.currentScript?.src;
  const warnedScripts = new Set();
  const presentWarning =
    'jQuery readiness: jQuery is present. Existing globals are left in place.';

  // eslint-disable-next-line drupal-jquery/no-reference -- Detects a preexisting global.
  if (typeof window.jQuery !== 'undefined') {
    console.warn(presentWarning);
    return;
  }

  ['jQuery', '$'].forEach((name) => {
    const original = Object.getOwnPropertyDescriptor(window, name);
    if (
      original?.configurable === false ||
      typeof window[name] !== 'undefined'
    ) {
      return;
    }

    Object.defineProperty(window, name, {
      configurable: true,
      get() {
        const urls = new Error().stack?.match(/https?:\/\/[^\s)]+/g) ?? [];
        const source = urls
          .map((url) => url.replace(/:\d+(?::\d+)?$/, ''))
          .find((url) => url !== canaryUrl);
        const script =
          source || document.currentScript?.src || 'unknown script';

        if (!warnedScripts.has(script)) {
          warnedScripts.add(script);
          console.warn('jQuery readiness: %s accessed window.%s', script, name);
        }
        return undefined;
      },
      set(value) {
        // A script that supplies its own global can still define it.
        Object.defineProperty(window, name, {
          configurable: true,
          enumerable: original?.enumerable ?? true,
          writable: true,
          value,
        });
        if (name === 'jQuery' && typeof value?.fn?.jquery === 'string') {
          console.warn(presentWarning);
        }
      },
    });
  });
})();
