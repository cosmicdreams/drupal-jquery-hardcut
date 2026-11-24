/**
 * @file
 * Warns when a script reads a missing jQuery global.
 */

(() => {
  const canaryUrl = document.currentScript?.src;
  const warnedLocations = new Set();
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
        const frames =
          new Error().stack?.match(/https?:\/\/[^\s)]+:\d+:\d+/g) ?? [];
        const caller = frames.find(
          (frame) => frame.replace(/:\d+:\d+$/, '') !== canaryUrl,
        );
        const location =
          caller?.replace(/:\d+$/, '') ||
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
