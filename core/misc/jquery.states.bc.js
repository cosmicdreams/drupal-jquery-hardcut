/**
 * @file
 * Adapts States collections, callbacks and events for explicit consumers.
 * @param {function(Element[]): object} jquery
 *   The explicitly loaded library instance.
 * @deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use a CSS
 *   selector and the native States callback arguments.
 * @see https://www.drupal.org/node/CHANGE_RECORD
 */

((jquery) => {
  const { Drupal } = window;
  const deprecated = new Set();
  const collections = new WeakMap();
  const legacyEvents = new WeakMap();

  /**
   * Reports each deprecated contract once.
   * @param {string} contract
   *   The deprecated surface.
   */
  function warn(contract) {
    if (!deprecated.has(contract)) {
      deprecated.add(contract);
      window.Drupal.deprecationError({
        message: `${contract} is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use a CSS selector and the native States callback arguments. See https://www.drupal.org/node/CHANGE_RECORD.`,
      });
    }
  }

  /**
   * Reuses the collection for a given array.
   * @param {Element[]} elements
   *   The native elements.
   * @return {object}
   *   The legacy collection.
   */
  function collection(elements) {
    if (!collections.has(elements)) {
      collections.set(elements, jquery(elements));
    }
    return collections.get(elements);
  }

  /**
   * Exposes the former collection member over the native element array.
   * @param {function(object)} owner
   *   The States constructor.
   * @param {string} name
   *   The public constructor name.
   */
  function collectionAlias(owner, name) {
    Object.defineProperty(owner.prototype, 'element', {
      configurable: true,
      get() {
        warn(`${name}#element`);
        return collection(this.elements);
      },
      set(input) {
        warn(`${name}#element`);
        const wrapped = input instanceof jquery ? input : jquery(input);
        this.elements = wrapped.toArray();
        collections.set(this.elements, wrapped);
      },
    });
  }

  /**
   * Converts a synthetic event without dispatching another DOM event.
   * @param {Event|object} event
   *   The active event.
   * @return {Event|undefined}
   *   The native view of the event.
   */
  function nativeEvent(event) {
    if (!(event instanceof jquery.Event)) {
      return event;
    }

    const original = event.originalEvent;
    const detail = { ...original?.detail };
    let changed = false;
    for (const field of ['value', 'oldValue', 'trigger']) {
      if (Object.hasOwn(event, field) && event[field] !== detail[field]) {
        detail[field] = event[field];
        changed = true;
      }
    }
    if (original && !event.isTrigger && !changed && !event.data) {
      legacyEvents.set(original, event);
      return original;
    }

    const native = new CustomEvent(event.type, {
      bubbles: original?.bubbles ?? true,
      cancelable: original?.cancelable ?? true,
      detail,
    });
    Object.defineProperties(native, {
      target: { value: event.target },
      currentTarget: { value: event.currentTarget },
      defaultPrevented: { get: () => event.isDefaultPrevented() },
      preventDefault: { value: () => event.preventDefault() },
      stopPropagation: { value: () => event.stopPropagation() },
      stopImmediatePropagation: {
        value: () => event.stopImmediatePropagation(),
      },
    });
    legacyEvents.set(native, event);
    return native;
  }

  /**
   * Keeps legacy overrides callable with their pinned receiver and arguments.
   * @param {object} states
   *   The Drupal States namespace.
   */
  function adaptCallbacks(states) {
    const nativeDefaults = new Set(states.compatibility.defaults);
    const facades = new WeakMap();

    // Preserve the ability of an override to save and call its predecessor.
    Object.values(states.Trigger.states).forEach((trigger) => {
      if (typeof trigger !== 'object' || trigger === null) {
        return;
      }
      Object.entries(trigger).forEach(([type, callback]) => {
        if (nativeDefaults.has(callback)) {
          const facade = function (event) {
            return callback.call(this.toArray(), nativeEvent(event));
          };
          facades.set(facade, callback);
          trigger[type] = facade;
        }
      });
    });

    states.compatibility.trigger = (name) => {
      const trigger = states.Trigger.states[name];
      const contract = `Drupal.states.Trigger.states.${name}`;
      if (typeof trigger === 'function') {
        return (elements) => {
          warn(contract);
          trigger.call(window, collection(elements));
        };
      }
      return Object.fromEntries(
        Object.entries(trigger ?? {}).map(([type, callback]) => [
          type,
          facades.get(callback) ??
            (nativeDefaults.has(callback)
              ? callback
              : function (event) {
                  warn(`${contract}.${type}`);
                  const wrapped =
                    event && (legacyEvents.get(event) ?? jquery.Event(event));
                  return event
                    ? callback.call(collection(this), wrapped)
                    : callback.call(collection(this));
                }),
        ]),
      );
    };
  }

  /**
   * Connects the adapter once the native States library is ready.
   */
  function initialize() {
    const { states, eventBindings } = window.Drupal;
    if (!states || !eventBindings) {
      return;
    }
    collectionAlias(states.Dependent, 'Drupal.states.Dependent');
    collectionAlias(states.Trigger, 'Drupal.states.Trigger');
    adaptCallbacks(states);

    const subscriptions = new Map();
    eventBindings.observe({
      add(binding) {
        if (!binding.states) {
          return;
        }
        const { target, type, listener, data } = binding;
        const element = jquery(target);
        const deliver = (event) => {
          if (data) {
            event.data = data;
          }
          return listener(nativeEvent(event));
        };
        const slot = eventBindings.claimLegacyEvent?.(jquery, binding, deliver);
        if (!slot) {
          element.on(type, data, deliver);
        }
        // Deliver at the original jQuery slot on mixed pages, even when a
        // previous handler has removed that slot or stopped propagation.
        binding.handlesNativeEvent = () => true;
        subscriptions.set(binding, () => {
          if (slot) {
            slot.remove();
          } else {
            element.off(type, deliver);
          }
          delete binding.handlesNativeEvent;
        });
      },
      remove(binding) {
        subscriptions.get(binding)?.();
        subscriptions.delete(binding);
      },
    });

    states.compatibility.enableLegacySelectors = () => {
      states.compatibility.select = (selector) => {
        warn('jQuery selector syntax in States');
        return jquery(selector).toArray();
      };
    };
  }

  if (Drupal?.states) {
    initialize();
  } else {
    document.addEventListener('drupal:states-ready', initialize, {
      once: true,
    });
  }
})(window.jQuery);
