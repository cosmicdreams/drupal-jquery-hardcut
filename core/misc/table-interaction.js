/**
 * @file
 * Shared event handling for table interactions.
 */

((Drupal) => {
  const tableListeners = new WeakMap();

  Drupal.behaviors.tableInteraction = {
    detach(context, settings, trigger) {
      if (trigger !== 'unload' || !context?.querySelectorAll) {
        return;
      }
      const owners = new Set(context.querySelectorAll('table, form'));
      for (let element = context; element; element = element.parentElement) {
        if (tableListeners.has(element)) {
          owners.add(element);
        }
      }
      owners.forEach((owner) => {
        const listeners = tableListeners.get(owner);
        if (!listeners) {
          return;
        }
        // Some cleanup removes nodes that own other bindings.
        const removed = [...listeners].filter(
          ({ target }) =>
            context.contains(owner) ||
            (target instanceof Node && context.contains(target)),
        );
        removed.forEach((binding) => {
          binding.stop();
          listeners.delete(binding);
        });
      });
    },
  };

  /**
   * Native operations shared by the three table libraries.
   *
   * @internal
   */
  Drupal.tableInteraction = {
    /**
     * Binds a native listener and registers its synthetic entry point.
     *
     * @param {HTMLTableElement} table
     *   The table that owns the listener.
     * @param {EventTarget} target
     *   The listener target.
     * @param {string} type
     *   The event type.
     * @param {Function} listener
     *   The native handler.
     */
    listen(table, target, type, listener) {
      const nativeListener = (event) => {
        if (this.allowNativeEvent(event)) {
          return listener.call(target, event);
        }
      };
      target.addEventListener(type, nativeListener);
      const unregister = Drupal.eventBindings.register({
        target,
        type,
        listener,
        tableInteraction: true,
      });
      this.own(table, target, () => {
        unregister();
        target.removeEventListener(type, nativeListener);
      });
    },

    /**
     * Determines whether a native event is handled here.
     *
     * @return {boolean}
     *   Whether to invoke the native handler.
     */
    allowNativeEvent() {
      return true;
    },

    /**
     * Inserts select-all markup and releases it with the owning header.
     *
     * @param {HTMLTableElement} table
     *   The table owning the headers.
     * @param {NodeList} headers
     *   The headers receiving a checkbox.
     * @param {string|Node} markup
     *   The themed checkbox.
     * @param {string} title
     *   The checkbox label.
     */
    insertCheckboxes(table, headers, markup, title) {
      let content = markup;
      if (typeof markup === 'string') {
        const template = document.createElement('template');
        template.innerHTML = markup.trim();
        content = template.content;
      }
      if (!(content instanceof Node)) {
        return;
      }
      headers.forEach((header, index) => {
        const fragment =
          index === headers.length - 1 ? content : content.cloneNode(true);
        const nodes =
          fragment instanceof DocumentFragment
            ? [...fragment.childNodes]
            : [fragment];
        header.prepend(fragment);
        const checkbox = header.querySelector('input[type="checkbox"]');
        if (checkbox) {
          checkbox.title = title;
        }
        this.own(table, header, () => nodes.forEach((node) => node.remove()));
      });
    },

    /**
     * Releases an owned listener when its table or target unloads.
     *
     * @param {HTMLTableElement} table
     *   The table that owns the listener.
     * @param {EventTarget} target
     *   The listener target.
     * @param {Function} stop
     *   Removes the listener and releases any associated state.
     */
    own(table, target, stop) {
      if (!tableListeners.has(table)) {
        tableListeners.set(table, new Set());
      }
      tableListeners.get(table).add({ target, stop });
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
     * @return {Function}
     *   Adopts methods defined by a subclass.
     */
    installEventOverrides(prototype, methods) {
      const adoptMethods = Object.entries(methods).map(
        ([method, eventIndex]) => {
          const wrapped = new WeakMap();

          function wrap(callback, builtin) {
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
                  builtin,
                );
              };
              Object.defineProperty(wrapper, 'nativeTableEvent', {
                get: () => callback.nativeTableEvent,
                set: (value) => {
                  callback.nativeTableEvent = value;
                },
              });
              wrapped.set(callback, wrapper);
              wrapped.set(wrapper, wrapper);
            }
            return wrapped.get(callback);
          }

          function install(target, callback, builtin = false) {
            let current = wrap(callback, builtin);
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

          install(prototype, prototype[method], true);
          return (instance) => {
            for (
              let target = Object.getPrototypeOf(instance);
              target && target !== prototype;
              target = Object.getPrototypeOf(target)
            ) {
              const descriptor = Object.getOwnPropertyDescriptor(
                target,
                method,
              );
              if (
                descriptor?.configurable &&
                typeof descriptor.value === 'function'
              ) {
                install(target, descriptor.value);
              }
            }
          };
        },
      );
      return (instance) => adoptMethods.forEach((adopt) => adopt(instance));
    },
  };
  document.dispatchEvent(new Event('drupal:table-interaction-ready'));
})(Drupal);
