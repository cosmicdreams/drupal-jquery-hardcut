/**
 * @file
 * Warns when a script reads or replaces a missing jQuery global.
 */

(() => {
  const canaryUrl = document.currentScript?.src;
  const warnedLocations = new Set();
  const warnedScripts = new Set();
  const presentWarning =
    'jQuery readiness: jQuery is present. Existing globals are left in place.';

  // eslint-disable-next-line drupal-jquery/no-reference, no-restricted-globals -- The canary must detect an existing global before installing its accessors.
  if (typeof window.jQuery !== 'undefined') {
    console.warn(presentWarning);
    return;
  }

  /**
   * Finds the calling script's URL, line and column in the browser stack.
   * @return {string|undefined}
   *   The frame outside the canary script, when available.
   */
  function callerFrame() {
    const frames =
      new Error().stack?.match(/https?:\/\/[^\s)]+:\d+:\d+/g) ?? [];
    return frames.find((frame) => frame.replace(/:\d+:\d+$/, '') !== canaryUrl);
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
        const location =
          callerFrame()?.replace(/:\d+$/, '') ||
          document.currentScript?.src ||
          'unknown script';

        if (!warnedLocations.has(location)) {
          warnedLocations.add(location);
          console.warn(
            'jQuery readiness: %s accessed window.%s',
            location,
            name,
          );
        }
        return undefined;
      },
      set(value) {
        // An undefined assignment leaves the diagnostic armed.
        if (value === undefined) {
          return;
        }
        const script =
          document.currentScript?.src ||
          callerFrame()?.replace(/:\d+:\d+$/, '') ||
          'unknown script';
        // A script that supplies its own global can still define it.
        Object.defineProperty(window, name, {
          configurable: true,
          enumerable: original?.enumerable ?? true,
          writable: true,
          value,
        });
        if (!warnedScripts.has(script)) {
          warnedScripts.add(script);
          console.warn('jQuery readiness: %s replaced a jQuery global', script);
        }
      },
    });
  });
})();
