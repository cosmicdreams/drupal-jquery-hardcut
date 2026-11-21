/**
 * @file
 * Adapts States collections, callbacks and events for explicit consumers.
 * @deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use a CSS
 *   selector and the native States callback arguments.
 * @see https://www.drupal.org/node/CHANGE_RECORD
 */

((jquery) => {
  if (typeof jquery !== 'function') {
    return;
  }

  const { Drupal } = window;
  const deprecated = new Set();
  const collections = new WeakMap();
  const legacyEvents = new WeakMap();

  /**
   * Reports each deprecated contract once.
   * @param {string} contract
   *   The deprecated surface.
   * @param {string} [migration]
   *   The replacement contract.
   */
  function warn(
    contract,
    migration = 'Use a CSS selector and the native States callback arguments.',
  ) {
    if (!deprecated.has(contract)) {
      deprecated.add(contract);
      window.Drupal.deprecationError({
        message: `${contract} is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. ${migration} See https://www.drupal.org/node/CHANGE_RECORD.`,
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
        return this.elements ? collection(this.elements) : undefined;
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
          nativeDefaults.has(callback)
            ? callback
            : function (event) {
                warn(`${contract}.${type}`);
                const wrapped =
                  event && (legacyEvents.get(event) ?? jquery.Event(event));
                return event
                  ? callback.call(collection(this), wrapped)
                  : callback.call(collection(this));
              },
        ]),
      );
    };
  }

  /**
   * Shares one jQuery.Event instance across bubbling targets for state events.
   * @param {object} eventBindings
   *   The Drupal event bindings registry.
   */
  function installSharedStateEvents(eventBindings) {
    const activeEvents = new WeakMap();
    const wrappedTypes = new Set();

    /**
     * Copies mutated legacy state fields back onto the native event detail.
     * @param {CustomEvent} original
     *   The native state event.
     * @param {object} legacyEvent
     *   The shared jQuery event.
     */
    function syncDetail(original, legacyEvent) {
      if (!original.detail || typeof original.detail !== 'object') {
        return;
      }
      for (const field of ['value', 'oldValue', 'trigger']) {
        if (Object.hasOwn(legacyEvent, field)) {
          original.detail[field] = legacyEvent[field];
        }
      }
    }

    /**
     * Wraps a state event type's special handle hook once to share one event.
     * @param {string} type
     *   The full state event type.
     */
    function wrapType(type) {
      if (wrappedTypes.has(type)) {
        return;
      }
      wrappedTypes.add(type);
      const specials = jquery.event.special;
      const special = specials[type] ?? {};
      const previousHandle = special.handle;
      specials[type] = special;

      special.handle = function (event, ...args) {
        const original = event.originalEvent;
        if (!original || event.isTrigger || !activeEvents.has(original)) {
          return (previousHandle || event.handleObj.handler).call(
            this,
            event,
            ...args,
          );
        }

        let shared = activeEvents.get(original);
        if (!shared) {
          shared = event;
          activeEvents.set(original, shared);
        }

        if (shared === event) {
          const result = (previousHandle || event.handleObj.handler).call(
            this,
            event,
            ...args,
          );
          syncDetail(original, event);
          return result;
        }

        shared.currentTarget = event.currentTarget;
        shared.delegateTarget = event.delegateTarget;
        shared.handleObj = event.handleObj;
        shared.data = event.data;
        const result = (previousHandle || event.handleObj.handler).call(
          this,
          shared,
          ...args,
        );
        if (shared.isDefaultPrevented()) {
          event.preventDefault();
        }
        if (shared.isImmediatePropagationStopped()) {
          event.stopImmediatePropagation();
        } else if (shared.isPropagationStopped()) {
          event.stopPropagation();
        }
        if (result !== undefined) {
          shared.result = result;
        }
        syncDetail(original, shared);
        return result;
      };
    }

    eventBindings.observePublications((event) => {
      if (!event.type.startsWith('state:')) {
        return undefined;
      }
      wrapType(event.type);
      activeEvents.set(event, null);
      return () => {
        activeEvents.delete(event);
      };
    });
  }

  /**
   * Reads the registered jQuery handlers for a target and event type.
   * @param {EventTarget} target
   *   The event target.
   * @param {string} type
   *   The event type.
   * @return {object[]}
   *   The registered handler descriptors.
   */
  function jqueryHandlers(target, type) {
    return jquery._data(target, 'events')?.[type] ?? [];
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
    installSharedStateEvents(eventBindings);

    // Mark state:checked change dispatches as synthetic for jQuery listeners.
    const change = jquery.event.special.change ?? {};
    const previousChangeHandle = change.handle;
    jquery.event.special.change = change;
    change.handle = function (event, ...args) {
      if (
        event.originalEvent &&
        states.compatibility.isCheckedChange?.(event.originalEvent)
      ) {
        event.isTrigger = 3;
      }
      return (previousChangeHandle ?? event.handleObj.handler).call(
        this,
        event,
        ...args,
      );
    };

    const subscriptions = new Map();
    const handlerToBinding = new WeakMap();
    const targetSubscriptions = new WeakMap();
    const removing = new WeakSet();

    /**
     * Reports whether another jQuery listener exists on the target and type.
     * @param {EventTarget} target
     *   The event target.
     * @param {string} type
     *   The event type.
     * @return {boolean}
     *   Whether a non-States jQuery handler is attached.
     */
    function hasExternalLegacyHandler(target, type) {
      return jqueryHandlers(target, type).some(
        ({ handler }) => !handlerToBinding.has(handler),
      );
    }

    // Track external jQuery(target).off(...) calls that remove a States slot.
    const originalRemove = jquery.event.remove;
    jquery.event.remove = function (target, ...args) {
      const result = originalRemove.call(this, target, ...args);
      const subs = targetSubscriptions.get(target);
      if (subs) {
        subs.forEach((sub) => {
          if (
            !removing.has(sub) &&
            !sub.removedByLegacy &&
            !jqueryHandlers(target, sub.type).some(
              ({ handler }) => handler === sub.handler,
            )
          ) {
            sub.removedByLegacy = true;
          }
        });
      }
      return result;
    };

    let replaying = true;
    eventBindings.observe({
      add(binding) {
        if (!binding.states) {
          return;
        }
        const { target, type, listener, data } = binding;
        const element = jquery(target);
        const decisions = new WeakMap();
        const sub = {
          binding,
          target,
          type,
          replayed: replaying,
          removedByLegacy: false,
          handler: null,
          slot: null,
        };

        const useLegacyForNative = (nativeEvt, fromNative) => {
          if (sub.removedByLegacy) {
            return true;
          }
          if (sub.replayed) {
            return false;
          }
          if (nativeEvt) {
            const previous = decisions.get(nativeEvt);
            if (previous && previous.fromNative !== fromNative) {
              decisions.delete(nativeEvt);
              return previous.legacy;
            }
          }
          const legacy = hasExternalLegacyHandler(target, type);
          if (nativeEvt) {
            decisions.set(nativeEvt, { fromNative, legacy });
          }
          return legacy;
        };

        const deliver = (event) => {
          const { originalEvent } = event;
          const isCheckedNative =
            type === 'change' &&
            originalEvent instanceof Event &&
            states.compatibility.isCheckedChange?.(originalEvent);
          if (!event.isTrigger || isCheckedNative) {
            if (
              sub.removedByLegacy ||
              !useLegacyForNative(originalEvent, false)
            ) {
              return undefined;
            }
            if (data) {
              event.data = data;
            }
            return listener(nativeEvent(event));
          }
          if (type.startsWith('state:')) {
            warn(
              `The jQuery synthetic trigger for ${type}`,
              'Use the same native event name and its documented payload.',
            );
          }
          if (data) {
            event.data = data;
          }
          return listener(nativeEvent(event));
        };

        const slot = eventBindings.claimLegacyEvent?.(jquery, binding, deliver);
        const handler = slot?.handler ?? deliver;
        if (!slot) {
          element.on(type, data, handler);
        }
        sub.slot = slot;
        sub.handler = handler;
        handlerToBinding.set(handler, binding);
        let subs = targetSubscriptions.get(target);
        if (!subs) {
          subs = new Set();
          targetSubscriptions.set(target, subs);
        }
        subs.add(sub);

        binding.handlesNativeEvent = (event) => useLegacyForNative(event, true);
        subscriptions.set(binding, () => {
          removing.add(sub);
          try {
            if (slot) {
              slot.remove(!sub.removedByLegacy);
            } else if (!sub.removedByLegacy) {
              element.off(type, handler);
            }
          } finally {
            removing.delete(sub);
            subs.delete(sub);
            delete binding.handlesNativeEvent;
          }
        });
      },
      remove(binding) {
        subscriptions.get(binding)?.();
        subscriptions.delete(binding);
      },
    });
    replaying = false;

    states.compatibility.ownsLegacyEvents = (instance, binding) =>
      instance === jquery && (!binding || subscriptions.has(binding));

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
