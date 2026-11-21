/**
 * @file
 * Probes native and legacy callback entry points.
 */

/**
 * Connects the fixture's prototype assignment slot to a legacy entry point.
 * @param {object} prototype
 *   The fixture prototype.
 * @param {function(HTMLFormElement): object} wrapForm
 *   The explicitly supplied collection constructor.
 * @return {object}
 *   The legacy dispatcher and its teardown.
 */
function connectLegacyHook(prototype, wrapForm) {
  const descriptor = Object.getOwnPropertyDescriptor(prototype, 'beforeSubmit');
  let assigned = descriptor.value;
  let connected = true;
  Object.defineProperty(prototype, 'beforeSubmit', {
    configurable: descriptor.configurable,
    enumerable: descriptor.enumerable,
    get: () => assigned,
    set: (callback) => {
      assigned = callback;
    },
  });

  return {
    invoke(instance, values, form, options) {
      return connected
        ? instance.beforeSubmit(values, wrapForm(form), options)
        : undefined;
    },
    disconnect() {
      if (connected) {
        Object.defineProperty(prototype, 'beforeSubmit', {
          ...descriptor,
          value: assigned,
        });
        connected = false;
      }
    },
  };
}

/**
 * Exercises the callback chains on an existing fixture page.
 * @param {HTMLElement} target
 *   The fixture container.
 * @param {function(HTMLFormElement): object} [library]
 *   The jQuery function supplied by the mixed page.
 * @return {Array}
 *   The observed calls, argument identities, and return values.
 */
export function runCallbackChain(target, library) {
  const form = target.appendChild(document.createElement('form'));
  const values = [];
  const options = {};
  const result = {};
  const trace = [];
  const nativeArgs = [values, form, options];
  let conversions = 0;
  let collection;

  const builtIn = function (...args) {
    trace.push([
      'built-in',
      this === instance,
      args.length,
      args[0] === values,
      args[1] === form,
      args[2] === options,
    ]);
    return result;
  };

  const nativeOverride = function (...args) {
    trace.push(['native override', this === instance, conversions]);
    return builtIn.apply(this, args);
  };

  /**
   * Models a prototype hook with a separate native entry point.
   */
  class Fixture {
    invokeNative(...args) {
      return this.nativeHook(...args);
    }

    // A getter installed later cannot change an already saved function.
    beforeSubmit(fields, wrappedForm, settings) {
      return builtIn.call(this, fields, wrappedForm[0], settings);
    }
  }

  const { prototype } = Fixture;
  const instance = new Fixture();
  instance.nativeHook = builtIn;
  trace.push([
    'native default result',
    instance.invokeNative(...nativeArgs) === result,
  ]);

  const original = prototype.beforeSubmit;
  const descriptor = Object.getOwnPropertyDescriptor(prototype, 'beforeSubmit');
  const first = function (...args) {
    trace.push([
      'first',
      this === instance,
      args.length,
      args[0] === values,
      args[1] instanceof library,
      args[1][0] === form,
      args[2] === options,
      args[1] !== collection,
    ]);
    collection = args[1];
    return original.apply(this, args);
  };
  prototype.beforeSubmit = first;
  const beforeOptIn = prototype.beforeSubmit;
  instance.nativeHook = nativeOverride;
  trace.push([
    'before opt-in',
    beforeOptIn === first,
    instance.invokeNative(...nativeArgs) === result,
  ]);
  if (library === undefined) {
    return trace;
  }

  const bridge = connectLegacyHook(prototype, (element) => {
    conversions += 1;
    return library(element);
  });
  const previous = prototype.beforeSubmit;
  trace.push(['late opt-in', previous === beforeOptIn, conversions]);
  const second = function (...args) {
    trace.push(['second', this === instance, args.length]);
    const returned = previous.apply(this, args);
    trace.push([
      'predecessor result',
      returned === result,
      args[1] === collection,
    ]);
    return false;
  };
  prototype.beforeSubmit = second;
  trace.push([
    'mixed native',
    prototype.beforeSubmit === second,
    instance.invokeNative(...nativeArgs) === result,
  ]);
  trace.push([
    'legacy veto',
    bridge.invoke(instance, ...nativeArgs) === false,
    conversions,
  ]);
  trace.push([
    'legacy veto again',
    bridge.invoke(instance, ...nativeArgs) === false,
    conversions,
  ]);

  prototype.beforeSubmit = original;
  trace.push([
    'restored',
    prototype.beforeSubmit === original,
    bridge.invoke(instance, ...nativeArgs) === result,
    conversions,
  ]);
  bridge.disconnect();
  const restored = Object.getOwnPropertyDescriptor(prototype, 'beforeSubmit');
  trace.push([
    'disconnected',
    restored.value === original,
    restored.writable === descriptor.writable,
    restored.enumerable === descriptor.enumerable,
    restored.configurable === descriptor.configurable,
    bridge.invoke(instance, ...nativeArgs) === undefined,
    conversions,
  ]);
  trace.push([
    'native after teardown',
    instance.invokeNative(...nativeArgs) === result,
    conversions,
  ]);
  return trace;
}
