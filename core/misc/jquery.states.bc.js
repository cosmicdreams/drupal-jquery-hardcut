/**
 * @file
 * Adapts States collections, callbacks and events for explicit consumers.
 * @deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use a CSS
 *   selector and the native States callback arguments.
 * @see https://www.drupal.org/node/CHANGE_RECORD
 * @param {function(HTMLElement): object} jquery
 *   The explicitly loaded jQuery instance.
 */

((jquery) => {
  if (typeof jquery !== 'function') {
    return;
  }

  const { Drupal } = window;
  const deprecated = new Set();
  const collections = new WeakMap();
  const legacyEvents = new WeakMap();
  // The jQuery bridge flags the handler of every slot it binds as core code.
  const coreHandler = Symbol.for('Drupal.eventBindings.coreHandler');

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
   * Calls a listener while the native payload shows the jQuery event's fields.
   *
   * A special.handle hook may edit or delete fields of the shared jQuery event
   * before it calls the handler. The listener reads those changes, and the
   * payload is restored after it returns so the bridge still writes back only
   * the hook's final values.
   * @param {Event} native
   *   The native event the listener receives.
   * @param {object} event
   *   The jQuery event the handler was invoked with.
   * @param {function(Event): unknown} listener
   *   The States listener.
   * @return {unknown}
   *   The listener's return value.
   */
  function withLivePayload(native, event, listener) {
    const { detail } = native;
    const saved = [];
    if (detail && typeof detail === 'object') {
      for (const field of ['value', 'oldValue', 'trigger']) {
        const had = Object.hasOwn(detail, field);
        if (Object.hasOwn(event, field)) {
          if (event[field] !== detail[field]) {
            saved.push([field, had, detail[field]]);
            detail[field] = event[field];
          }
        } else if (had) {
          // The bridge copies defined payload fields to the shared event, so
          // a missing one was deleted by a hook or was undefined.
          saved.push([field, had, detail[field]]);
          delete detail[field];
        }
      }
    }
    try {
      return listener(native);
    } finally {
      saved.forEach(([field, had, value]) => {
        if (had) {
          detail[field] = value;
        } else {
          delete detail[field];
        }
      });
    }
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

    const warnSyntheticState = (type) => {
      warn(
        `The jQuery synthetic trigger for ${type}`,
        'Use the same native event name and its documented payload.',
      );
    };
    states.compatibility.warnSyntheticState = warnSyntheticState;

    // Pinned state:collapsed called .trigger('click'). jQuery handlers run
    // first, and the native click() follows only when none cancelled it.
    states.compatibility.click = (summary) => {
      jquery(summary).trigger('click');
      return true;
    };

    const subscriptions = new Map();
    const handlerToBinding = new WeakMap();
    const targetSubscriptions = new WeakMap();
    const typeSubscriptions = new Map();
    const preExistingLegacy = new WeakMap();
    const removing = new WeakSet();

    /**
     * Reports whether legacy jQuery code handles the target and type.
     *
     * Pinned States bound and dispatched every event through jQuery, so a
     * special.handle hook for any type it uses wrapped the States handler.
     * Hooks and slot handlers that core owns do not count.
     * @param {EventTarget} target
     *   The event target.
     * @param {string} type
     *   The base event type.
     * @return {boolean}
     *   Whether a non-States jQuery handler or a legacy hook is present.
     */
    function hasExternalLegacyHandler(target, type) {
      return (
        Boolean(eventBindings.hasLegacySpecialHandle?.(jquery, type)) ||
        jqueryHandlers(target, type).some(
          ({ handler }) =>
            !handlerToBinding.has(handler) && !handler[coreHandler],
        )
      );
    }

    // Snapshot pre-existing external jQuery handlers before dispatch so a
    // one-shot .one() handler registered before states.js loads still runs the
    // States slot through the jQuery queue even after unbinding itself.
    // Only subscriptions on the path of a States publication can receive it.
    const onPath = (target, origin) =>
      !origin ||
      target === window ||
      (target instanceof Node && target.contains(origin));
    eventBindings.observePublications((event) => {
      const subs = typeSubscriptions.get(event.type);
      if (!subs) {
        return undefined;
      }
      const origin = states.compatibility.publicationTarget?.(event);
      let matched;
      subs.forEach((sub) => {
        if (
          onPath(sub.target, origin) &&
          hasExternalLegacyHandler(sub.target, sub.type)
        ) {
          if (!matched) {
            matched = new Set();
          }
          matched.add(sub);
        }
      });
      if (!matched) {
        return undefined;
      }
      preExistingLegacy.set(event, matched);
      return () => preExistingLegacy.delete(event);
    });

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

    eventBindings.observe({
      add(binding) {
        if (!binding.states) {
          return;
        }
        const { target, type, namespace, listener, data } = binding;
        const name = namespace ? `${type}.${namespace}` : type;
        const element = jquery(target);
        const decisions = new WeakMap();
        const sub = {
          binding,
          target,
          type,
          removedByLegacy: false,
          handler: null,
          slot: null,
        };

        const useLegacyForNative = (nativeEvt, fromNative) => {
          if (
            sub.removedByLegacy ||
            (nativeEvt && preExistingLegacy.get(nativeEvt)?.has(sub))
          ) {
            return true;
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
          const nativeOriginal =
            event.originalEvent ?? eventBindings.getProjectedOriginal?.(event);
          const isProjectedNative =
            !event.isTrigger || nativeOriginal instanceof Event;
          if (isProjectedNative) {
            if (
              sub.removedByLegacy ||
              (nativeOriginal &&
                (window.Drupal.ajax?.compatibility?.isSyntheticDefaultAction?.(
                  nativeOriginal,
                ) ??
                  eventBindings.isForeignDefaultAction?.(nativeOriginal))) ||
              !useLegacyForNative(nativeOriginal, false)
            ) {
              return undefined;
            }
            if (data) {
              event.data = data;
            }
            if (nativeOriginal && !event.originalEvent) {
              legacyEvents.set(nativeOriginal, event);
              return withLivePayload(nativeOriginal, event, listener);
            }
            return listener(nativeEvent(event));
          }
          if (type.startsWith('state:')) {
            warnSyntheticState(type);
          }
          if (data) {
            event.data = data;
          }
          return listener(nativeEvent(event));
        };

        const slot = eventBindings.claimLegacyEvent?.(jquery, binding, deliver);
        const handler = slot?.handler ?? deliver;
        if (!slot) {
          element.on(name, data, handler);
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
        let typeSubs = typeSubscriptions.get(type);
        if (!typeSubs) {
          typeSubs = new Set();
          typeSubscriptions.set(type, typeSubs);
        }
        typeSubs.add(sub);

        binding.handlesNativeEvent = (event) => useLegacyForNative(event, true);
        subscriptions.set(binding, () => {
          removing.add(sub);
          try {
            if (slot) {
              slot.remove(!sub.removedByLegacy);
            } else if (!sub.removedByLegacy) {
              element.off(name, handler);
            }
          } finally {
            removing.delete(sub);
            subs.delete(sub);
            if (!subs.size) {
              targetSubscriptions.delete(target);
            }
            typeSubs.delete(sub);
            if (!typeSubs.size) {
              typeSubscriptions.delete(type);
            }
            delete binding.handlesNativeEvent;
          }
        });
      },
      remove(binding) {
        subscriptions.get(binding)?.();
        subscriptions.delete(binding);
      },
    });

    states.compatibility.ownsLegacyEvents = (instance, binding) =>
      instance === jquery && (!binding || subscriptions.has(binding));

    // Report delivered States bindings through the bridge's generic hook,
    // after any adapter that defined it earlier.
    const previousOwns = eventBindings.ownsLegacyEvents;
    eventBindings.ownsLegacyEvents = (instance, binding) =>
      Boolean(previousOwns?.(instance, binding)) ||
      Boolean(
        binding?.states &&
        states.compatibility.ownsLegacyEvents?.(instance, binding),
      );

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
