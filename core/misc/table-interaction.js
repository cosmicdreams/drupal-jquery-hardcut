/**
 * @file
 * Shared event handling for table interactions.
 */

((Drupal) => {
  /**
   * Native operations shared by the three table libraries.
   *
   * @internal
   */
  Drupal.tableInteraction = {
    /**
     * Binds a native listener and registers its synthetic entry point.
     *
     * @param {EventTarget} target
     *   The listener target.
     * @param {string} type
     *   The event type.
     * @param {Function} listener
     *   The native handler.
     */
    listen(target, type, listener) {
      target.addEventListener(type, listener);
      Drupal.eventBindings.register({
        target,
        type,
        listener,
        tableInteraction: true,
      });
    },

    /**
     * Calls an event override through the current compatibility boundary.
     *
     * @param {Function} callback
     *   The original callback.
     * @param {object} context
     *   The instance receiving the event.
     * @param {Array} args
     *   The callback arguments.
     * @return {*}
     *   The callback result.
     */
    invokeEventCallback(callback, context, args) {
      return callback.apply(context, args);
    },

    /**
     * Keeps saved event callbacks callable after a late adapter installation.
     *
     * @param {object} prototype
     *   The prototype declaring the native methods.
     * @param {object} methods
     *   Method names mapped to the event argument index.
     */
    installEventOverrides(prototype, methods) {
      Object.entries(methods).forEach(([method, eventIndex]) => {
        const wrapped = new WeakMap();

        function wrap(callback) {
          if (
            typeof callback !== 'function' ||
            wrapped.get(callback) === callback
          ) {
            return callback;
          }
          if (!wrapped.has(callback)) {
            const wrapper = function (...args) {
              return Drupal.tableInteraction.invokeEventCallback(
                callback,
                this,
                args,
                eventIndex,
                method,
              );
            };
            wrapped.set(callback, wrapper);
            wrapped.set(wrapper, wrapper);
          }
          return wrapped.get(callback);
        }

        function install(target, callback) {
          let current = wrap(callback);
          Object.defineProperty(target, method, {
            configurable: true,
            enumerable:
              Object.getOwnPropertyDescriptor(target, method)?.enumerable ??
              true,
            get() {
              return current;
            },
            set(value) {
              if (this === target) {
                current = wrap(value);
              } else {
                install(this, value);
              }
            },
          });
        }

        install(prototype, prototype[method]);
      });
    },
  };
  document.dispatchEvent(new Event('drupal:table-interaction-ready'));
})(Drupal);
