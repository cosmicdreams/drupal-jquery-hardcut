/**
 * @file
 * Shared event handling for table interactions.
 */

((Drupal) => {
  const tableListeners = new WeakMap();
  const pending = new Set();
  const observer = new MutationObserver((records) => {
    if (records.some(({ removedNodes }) => removedNodes.length)) {
      releaseDisconnected();
    }
  });

  /**
   * Releases pending bindings after their owner or target leaves the page.
   */
  function releaseDisconnected() {
    pending.forEach((binding) => {
      const { owner, target } = binding;
      if (
        !owner.isConnected ||
        (target instanceof Node && !target.isConnected)
      ) {
        binding.stop();
      }
    });
    if (!pending.size) {
      observer.disconnect();
    }
  }

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
      const wasIdle = !pending.size;
      owners.forEach((owner) => {
        tableListeners.get(owner)?.forEach((binding) => {
          const { target } = binding;
          if (
            context.contains(owner) ||
            (target instanceof Node && context.contains(target))
          ) {
            pending.add(binding);
          }
        });
      });
      if (pending.size) {
        // Unload can keep a dialog in place or precede a delayed removal.
        if (wasIdle) {
          observer.observe(document, { childList: true, subtree: true });
        }
        queueMicrotask(releaseDisconnected);
      }
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
     * @param {HTMLElement} owner
     *   The table or form that owns the listener.
     * @param {EventTarget} target
     *   The listener target.
     * @param {string} type
     *   The event type.
     * @param {Function} listener
     *   The native handler.
     * @return {Function}
     *   Releases the listener before its table or target unloads.
     */
    listen(owner, target, type, listener) {
      const nativeListener = (event) => {
        if (this.allowNativeEvent(event)) {
          return listener.call(target, event);
        }
      };
      const setNativeActive = (active) => {
        if (active) {
          target.addEventListener(type, nativeListener);
        } else {
          target.removeEventListener(type, nativeListener);
        }
      };
      setNativeActive(true);
      const unregister = Drupal.eventBindings.register({
        target,
        type,
        listener,
        setNativeActive,
        tableInteraction: true,
      });
      return this.own(owner, target, () => {
        unregister();
        setNativeActive(false);
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
     * Removes adjacent controls left behind when a table is replaced.
     *
     * @param {HTMLTableElement} table
     *   The unloading table.
     * @param {Node[]} controls
     *   Nodes inserted beside the table or its wrapper.
     */
    removeOrphanedControls(table, controls) {
      if (!table.isConnected) {
        controls.forEach((node) => {
          if (node.isConnected) {
            node.remove();
          }
        });
      }
    },

    /**
     * Inserts select-all markup in headers that need a checkbox.
     *
     * @param {HTMLElement[]} headers
     *   The headers receiving a checkbox.
     * @param {string|Node} markup
     *   The themed checkbox.
     * @param {string} title
     *   The checkbox label.
     */
    insertCheckboxes(headers, markup, title) {
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
        header.prepend(fragment);
        const checkbox = header.querySelector('input[type="checkbox"]');
        if (checkbox) {
          checkbox.title = title;
        }
      });
    },

    /**
     * Releases an owned listener when its table or target unloads.
     *
     * @param {HTMLElement} owner
     *   The table or form that owns the listener.
     * @param {EventTarget} target
     *   The listener target.
     * @param {Function} stop
     *   Removes the listener and releases any associated state.
     * @return {Function}
     *   Releases the binding immediately.
     */
    own(owner, target, stop) {
      if (!tableListeners.has(owner)) {
        tableListeners.set(owner, new Set());
      }
      const listeners = tableListeners.get(owner);
      const binding = {
        owner,
        target,
        stop() {
          pending.delete(binding);
          if (listeners.delete(binding)) {
            stop();
          }
        },
      };
      listeners.add(binding);
      return binding.stop;
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
