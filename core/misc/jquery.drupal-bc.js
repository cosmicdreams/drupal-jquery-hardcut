/**
 * @file
 * Adapts owned events for explicitly connected jQuery instances.
 * @param {function(HTMLElement): object} instance
 *   The explicitly loaded jQuery instance.
 */

(function (instance) {
  const foreignTriggers = [];
  const legacySlots = new WeakMap();
  const projectedOriginals = new WeakMap();
  // Maps each core-owned special.handle hook to the hook it wraps, if any.
  const coreHandles = new WeakMap();
  // The special.handle hooks this bridge installs for its projections.
  const projectionHandles = new WeakSet();

  /**
   * Records a special.handle hook that core owns.
   *
   * Legacy detection looks through these hooks to the hook each one wraps.
   * @param {function(...unknown): unknown} handle
   *   The installed special.handle hook.
   * @param {function(...unknown): unknown} [previous]
   *   The special.handle hook it wraps, if any.
   * @internal
   */
  function registerCoreSpecialHandle(handle, previous) {
    if (typeof handle === 'function') {
      coreHandles.set(handle, previous);
    }
  }

  // jQuery's own special hooks, such as mouseenter, exist before any other
  // script can use this instance, because this file loads right after it.
  if (typeof instance === 'function') {
    Object.values(instance.event.special).forEach((special) => {
      registerCoreSpecialHandle(special?.handle);
    });
  }

  /**
   * Gives a dedicated bridge the slot reserved in registry order.
   */
  function claimLegacyEvent(jquery, binding, deliver) {
    const slot = legacySlots.get(jquery)?.get(binding);
    if (slot && !slot.claimed) {
      slot.claimed = true;
      slot.deliver = deliver;
      return slot;
    }
    return undefined;
  }

  /**
   * Observes the owned bindings using one explicit jQuery instance.
   * @param {function(HTMLElement): object} jquery
   *   The connected jQuery instance.
   * @param {object} registry
   *   The owned event binding registry.
   * @return {function(): void}
   *   Removes this observer's legacy subscriptions.
   * @internal
   */
  function observeLegacyEvents(jquery, registry) {
    const subscriptions = legacySlots.get(jquery) || new Map();
    legacySlots.set(jquery, subscriptions);

    return registry.observe({
      add(binding) {
        const { target, type, namespace, selector, listener } = binding;
        if (
          subscriptions.has(binding) ||
          window.Drupal.ajax?.compatibility?.ownsLegacyEvents?.(
            jquery,
            binding,
          ) ||
          window.Drupal.states?.compatibility?.ownsLegacyEvents?.(
            jquery,
            binding,
          )
        ) {
          return;
        }
        // Reserve element slots for the dedicated bridges together: observe()
        // replays all bindings in registration order, regardless of owner.
        const tableSlot =
          jquery === instance &&
          binding.tableInteraction &&
          target instanceof Element &&
          !binding.handlesNativeEvent;
        // A select has no native change() method for jQuery's trigger to invoke.
        const selectChange =
          !selector && type === 'change' && target instanceof HTMLSelectElement;
        const directActivation =
          !selector &&
          ((type === 'click' && target instanceof HTMLAnchorElement) ||
            (type === 'submit' && target instanceof HTMLFormElement));
        const forwardsSynthetic =
          binding.ajax ||
          binding.states ||
          type === 'compatibility:probe' ||
          directActivation ||
          selectChange;
        if (!forwardsSynthetic && !tableSlot) {
          return;
        }

        const name = namespace ? `${type}.${namespace}` : type;

        /**
         * Routes one synthetic invocation to the owned operation.
         * @param {object} event
         *   The active jQuery event.
         * @param {...unknown} args
         *   The positional arguments from the trigger.
         * @return {unknown}
         *   The result jQuery should use for this handler.
         */
        function deliver(event, ...args) {
          // The pinned jQuery trigger sets isTrigger for both trigger variants.
          // A DOM event already reaches the owned listener directly.
          if (
            !forwardsSynthetic ||
            !event.isTrigger ||
            projectedOriginals.has(event) ||
            (!binding.ajax &&
              (event.originalEvent ||
                (directActivation && event.target !== target)))
          ) {
            return undefined;
          }

          if (binding.ajax && window.Drupal.ajax?.compatibility) {
            return listener.call(this, event, ...args);
          }

          // Standalone Ajax bindings still receive a native event shape.
          // Preserve the legacy metadata for other owned registrations.
          const projected = binding.ajax
            ? new Event(event.type, { cancelable: true })
            : Object.create(event);
          Object.defineProperties(projected, {
            target: { value: event.target },
            currentTarget: { value: event.currentTarget },
            key: { value: event.key },
            which: { value: event.which },
            submitter: {
              value: event.submitter ?? event.originalEvent?.submitter,
            },
            isTrigger: { value: event.isTrigger },
            defaultPrevented: { get: () => event.isDefaultPrevented() },
            preventDefault: { value: () => event.preventDefault() },
            stopPropagation: { value: () => event.stopPropagation() },
            stopImmediatePropagation: {
              value: () => event.stopImmediatePropagation(),
            },
          });
          if (binding.states && !projected.detail) {
            if (type.startsWith('state:')) {
              window.Drupal.states?.compatibility?.warnSyntheticState?.(type);
            }
            Object.defineProperty(projected, 'detail', {
              value: {
                value: event.value,
                oldValue: event.oldValue,
                trigger: event.trigger,
              },
            });
          }
          return listener.call(this, projected, ...args);
        }

        const element = jquery(target);
        const slot = {
          deliver,
          handler(...args) {
            return slot.deliver.apply(this, args);
          },
          remove(unbind = true) {
            subscriptions.delete(binding);
            if (unbind) {
              element.off(name, selector, slot.handler);
            }
          },
        };
        element.on(name, selector, slot.handler);
        subscriptions.set(binding, slot);
      },
      remove(binding) {
        const slot = subscriptions.get(binding);
        if (slot && !slot.claimed) {
          slot.remove();
        }
      },
    });
  }

  /**
   * Projects explicitly marked native events at jQuery's handler dispatch.
   * @param {object} jquery
   *   The explicitly connected jQuery instance.
   * @param {string} type
   *   The unmapped event type. Install one projection per instance and type.
   * @param {{get: function(Event): (object|undefined)}} payloads
   *   Maps native CustomEvents to objects with optional properties and args.
   * @return {function(): void}
   *   Disconnects the projection.
   * @internal
   */
  function observeNativePayloads(jquery, type, payloads) {
    const specials = jquery.event.special;
    const existing = specials[type];
    const special = existing ?? {};
    const previousHandle = special.handle;
    const hadHandle = Object.hasOwn(special, 'handle');
    const projected = new WeakSet();
    let connected = true;

    /**
     * Supplies the declared payload to the existing handler.
     * @param {object} event
     *   The active jQuery event.
     * @param {...unknown} args
     *   The arguments already supplied by jQuery.
     * @return {unknown}
     *   The downstream handler's return value.
     */
    function handle(event, ...args) {
      const payload =
        connected &&
        !event.isTrigger &&
        event.handleObj.origType === type &&
        event.originalEvent?.type === type &&
        payloads.get(event.originalEvent);
      if (payload && !projected.has(event)) {
        // Preserve writes by earlier handlers on this jQuery event.
        Object.assign(event, payload.properties);
        projected.add(event);
      }
      return (previousHandle || event.handleObj.handler).call(
        this,
        event,
        ...(payload?.args ?? args),
      );
    }

    special.handle = handle;
    registerCoreSpecialHandle(handle, previousHandle);
    projectionHandles.add(handle);
    if (!existing) {
      specials[type] = special;
    }
    return () => {
      connected = false;
      // A later hook may wrap this handler. Leave it in place and pass through.
      if (special.handle !== handle) {
        return;
      }
      if (hadHandle) {
        special.handle = previousHandle;
      } else {
        delete special.handle;
      }
      if (
        !existing &&
        specials[type] === special &&
        !Reflect.ownKeys(special).length
      ) {
        delete specials[type];
      }
    };
  }

  /**
   * Projects States publications as one synthetic jQuery event per instance.
   * @param {object} jquery
   *   The explicitly connected jQuery instance.
   * @param {string} type
   *   The unmapped event type.
   * @param {WeakMap} activeSynthetic
   *   Maps active native events to their projection record.
   * @return {function(): void}
   *   Disconnects the synthetic projection.
   * @internal
   */
  function observeSyntheticProjection(jquery, type, activeSynthetic) {
    const specials = jquery.event.special;
    const existing = specials[type];
    const special = existing ?? {};
    const previousHandle = special.handle;
    const hadHandle = Object.hasOwn(special, 'handle');
    let connected = true;

    /**
     * Delivers one synthetic event without originalEvent across targets.
     * @param {object} event
     *   The active jQuery event.
     * @param {...unknown} args
     *   Additional handler arguments.
     * @return {unknown}
     *   The downstream handler's return value.
     */
    function handle(event, ...args) {
      const original = event.originalEvent;
      const record =
        connected &&
        original &&
        !event.isTrigger &&
        event.handleObj.origType === type &&
        original.type === type
          ? activeSynthetic.get(original)
          : undefined;
      if (!record) {
        return (previousHandle || event.handleObj.handler).call(
          this,
          event,
          ...args,
        );
      }

      let { shared } = record;
      if (!shared) {
        shared = record.properties
          ? jquery.Event(event.type, record.properties)
          : jquery.Event(event.type);
        shared.target = event.target;
        shared.isTrigger = 3;
        record.shared = shared;
        projectedOriginals.set(shared, original);
      }

      shared.currentTarget = event.currentTarget;
      shared.delegateTarget = event.delegateTarget;
      shared.handleObj = event.handleObj;
      shared.data = event.data;
      // A pinned .trigger() without namespaces set an empty namespace.
      shared.namespace = event.namespace || '';

      // Start from the current payload so an edit made through another
      // instance is visible, and remember it to detect this handler's edits.
      const { detail } = original;
      const fields =
        record.syncState && detail && typeof detail === 'object'
          ? ['value', 'oldValue', 'trigger']
          : [];
      const before = {};
      for (const field of fields) {
        if (Object.hasOwn(detail, field) && shared[field] !== detail[field]) {
          shared[field] = detail[field];
        }
        before[field] = shared[field];
      }

      const result = (previousHandle || event.handleObj.handler).call(
        this,
        shared,
        ...args,
      );
      if (result === false) {
        shared.preventDefault();
        shared.stopPropagation();
      }
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
      // Write back only what this handler changed, so a passive instance
      // never restores a value another instance already edited.
      for (const field of fields) {
        if (shared[field] !== before[field]) {
          detail[field] = shared[field];
        }
      }
      return result;
    }

    special.handle = handle;
    registerCoreSpecialHandle(handle, previousHandle);
    projectionHandles.add(handle);
    if (!existing) {
      specials[type] = special;
    }
    return () => {
      connected = false;
      if (special.handle !== handle) {
        return;
      }
      if (hadHandle) {
        special.handle = previousHandle;
      } else {
        delete special.handle;
      }
      if (
        !existing &&
        specials[type] === special &&
        !Reflect.ownKeys(special).length
      ) {
        delete specials[type];
      }
    };
  }

  /**
   * Reports whether a special.handle hook that core does not own exists.
   * @param {object} jquery
   *   The connected jQuery instance.
   * @param {string} type
   *   The event type.
   * @return {boolean}
   *   Whether jQuery dispatch runs handlers through a legacy hook.
   * @internal
   */
  function hasLegacySpecialHandle(jquery, type) {
    let handle = jquery.event.special[type]?.handle;
    while (coreHandles.has(handle)) {
      handle = coreHandles.get(handle);
    }
    return typeof handle === 'function';
  }

  /**
   * Lets a callback see each native state:* event before jQuery dispatches it.
   * @param {object} jquery
   *   The explicitly connected jQuery instance.
   * @param {function(CustomEvent): void} callback
   *   Receives the native event.
   * @return {function(): void}
   *   Stops observing.
   */
  function observeNativeStateDispatch(jquery, callback) {
    const previous = jquery.event.dispatch;
    let connected = true;

    /**
     * Reports a native state:* event, then runs jQuery's dispatch.
     * @param {Event|object} nativeEvent
     *   The event jQuery dispatches.
     * @return {unknown}
     *   The result of jQuery's dispatch.
     */
    function dispatch(nativeEvent) {
      if (
        connected &&
        nativeEvent instanceof CustomEvent &&
        nativeEvent.type.startsWith('state:')
      ) {
        callback(nativeEvent);
      }
      return previous.apply(this, arguments);
    }
    jquery.event.dispatch = dispatch;
    return () => {
      connected = false;
      if (jquery.event.dispatch === dispatch) {
        jquery.event.dispatch = previous;
      }
    };
  }

  /**
   * Copies the defined state fields of a payload.
   * @param {object} [detail]
   *   The native event detail.
   * @return {object}
   *   The value, oldValue and trigger fields that are not undefined.
   */
  function stateProperties(detail) {
    const properties = {};
    for (const field of ['value', 'oldValue', 'trigger']) {
      const value =
        detail && typeof detail === 'object' && Object.hasOwn(detail, field)
          ? detail[field]
          : undefined;
      // jQuery's event constructor skips undefined properties.
      if (value !== undefined) {
        properties[field] = value;
      }
    }
    return properties;
  }

  /**
   * Projects the legacy payloads of supported owned publications.
   * @param {object} jquery
   *   The explicitly connected jQuery instance.
   * @param {object} registry
   *   The owned event registry.
   * @return {function(): void}
   *   Stops observing publications and removes the projections.
   */
  function observeOwnedPublications(jquery, registry) {
    const payloads = new WeakMap();
    const activeSynthetic = new WeakMap();
    const projections = new Map();
    // A state:* event dispatched natively, outside the registry, carries its
    // payload in detail. Legacy hooks receive it like a registry publication.
    const projectedPayloads = {
      get(event) {
        if (payloads.has(event)) {
          return payloads.get(event);
        }
        if (
          event instanceof CustomEvent &&
          event.type.startsWith('state:') &&
          !activeSynthetic.has(event)
        ) {
          return { properties: stateProperties(event.detail) };
        }
        return undefined;
      },
    };

    /**
     * Installs a projection, or wraps a hook that replaced it again.
     *
     * A library loaded later, for example through Ajax, may replace the
     * special.handle hook. Wrapping it again gives it the pinned event.
     * @param {string} key
     *   The projection key.
     * @param {string} type
     *   The event type.
     * @param {function(): function(): void} install
     *   Installs the projection and returns its disconnect function.
     */
    function project(key, type, install) {
      const current = projections.get(key);
      if (current) {
        // Other projections of this bridge may sit above this one. Only a
        // hook the bridge does not own needs the projection above it.
        let handle = jquery.event.special[type]?.handle;
        while (handle !== current.handle && projectionHandles.has(handle)) {
          handle = coreHandles.get(handle);
        }
        if (handle === current.handle) {
          return;
        }
        // The replaced projection stays in any chain as a pass-through.
        current.disconnect();
        projections.delete(key);
      }
      const disconnect = install();
      projections.set(key, {
        disconnect,
        handle: jquery.event.special[type].handle,
      });
    }

    const stop = registry.observePublications((event) => {
      const { type, detail } = event;
      const state = type.startsWith('state:');
      const statesSynthetic =
        window.Drupal.states?.compatibility?.isSyntheticTrigger?.(event) ||
        (state && window.Drupal.states?.compatibility?.isStateEvent?.(event));
      if (statesSynthetic) {
        if (activeSynthetic.has(event)) {
          return undefined;
        }
        const properties = state ? stateProperties(detail) : undefined;
        const key = `synthetic:${type}`;
        project(key, type, () =>
          observeSyntheticProjection(jquery, type, activeSynthetic),
        );
        activeSynthetic.set(event, {
          properties,
          syncState: state,
          shared: null,
        });
        return () => activeSynthetic.delete(event);
      }
      if ((!state && type !== 'columnschange') || payloads.has(event)) {
        return undefined;
      }

      const payload = state
        ? { properties: stateProperties(detail) }
        : { args: [Boolean(detail?.displayWeight)] };

      const key = `payload:${type}`;
      project(key, type, () =>
        observeNativePayloads(jquery, type, projectedPayloads),
      );
      payloads.set(event, payload);
      return () => payloads.delete(event);
    });

    const stopNative = observeNativeStateDispatch(jquery, (event) => {
      const { type } = event;
      if (
        !payloads.has(event) &&
        !activeSynthetic.has(event) &&
        hasLegacySpecialHandle(jquery, type)
      ) {
        project(`payload:${type}`, type, () =>
          observeNativePayloads(jquery, type, projectedPayloads),
        );
      }
    });

    return () => {
      stop();
      stopNative();
      Array.from(projections.values())
        .reverse()
        .forEach(({ disconnect }) => disconnect());
      projections.clear();
    };
  }

  /**
   * Tracks native default actions caused by an explicitly connected copy.
   */
  function observeForeignTriggers(jquery) {
    const previous = jquery.event.trigger;
    let connected = true;
    function trigger(event, data, target) {
      if (!connected) {
        return previous.apply(this, arguments);
      }
      const type = (typeof event === 'string' ? event : event.type).split(
        '.',
      )[0];
      foreignTriggers.push({ jquery, type, target: target || document });
      try {
        return previous.apply(this, arguments);
      } finally {
        foreignTriggers.pop();
      }
    }
    jquery.event.trigger = trigger;
    return () => {
      connected = false;
      if (jquery.event.trigger === trigger) {
        jquery.event.trigger = previous;
      }
    };
  }

  /**
   * Identifies the real event that accompanies a foreign synthetic dispatch.
   */
  function isForeignDefaultAction(event) {
    const frame = foreignTriggers.at(-1);
    if (frame?.type !== event.type || frame.target !== event.target) {
      return false;
    }
    const { jquery } = frame;
    // The dedicated request bridge tracks its own leveraged dispatch.
    if (window.Drupal.ajax?.compatibility?.ownsLegacyEvents?.(jquery)) {
      return false;
    }
    const phase = ['click', 'focus', 'blur'].includes(event.type)
      ? jquery._data(event.target, event.type)
      : null;
    if (
      jquery.event.triggered === event.type ||
      Array.isArray(phase) ||
      (phase && Object.hasOwn(phase, 'value'))
    ) {
      frame.defaultEvent = frame.defaultEvent || event;
      return frame.defaultEvent === event;
    }
    return false;
  }

  const connections = new WeakMap();

  /**
   * Connects one explicitly selected jQuery instance to owned events.
   * @param {object} jquery
   *   The jQuery instance to connect.
   * @return {function(): void}
   *   Disconnects the instance and removes its subscriptions.
   */
  function connect(jquery) {
    if (typeof jquery !== 'function') {
      return () => undefined;
    }

    if (!connections.has(jquery)) {
      const registry = window.Drupal.eventBindings;
      const stopTriggers = observeForeignTriggers(jquery);
      const stopBindings = observeLegacyEvents(jquery, registry);
      const stopPublications = observeOwnedPublications(jquery, registry);
      const disconnect = () => {
        if (connections.get(jquery) === disconnect) {
          stopTriggers();
          stopBindings();
          stopPublications();
          connections.delete(jquery);
        }
      };
      connections.set(jquery, disconnect);
    }
    return connections.get(jquery);
  }

  /**
   * Allows explicit opt-in and connects the supplied instance.
   */
  function initialize() {
    const registry = window.Drupal.eventBindings;
    registry.connectLegacy = registry.connectLegacy ?? connect;
    registry.claimLegacyEvent = registry.claimLegacyEvent ?? claimLegacyEvent;
    registry.hasLegacySpecialHandle =
      registry.hasLegacySpecialHandle ?? hasLegacySpecialHandle;
    registry.registerCoreSpecialHandle =
      registry.registerCoreSpecialHandle ?? registerCoreSpecialHandle;
    registry.isForeignDefaultAction =
      registry.isForeignDefaultAction || isForeignDefaultAction;
    registry.getProjectedOriginal =
      registry.getProjectedOriginal ??
      ((event) => projectedOriginals.get(event));
    registry.connectLegacy(instance);
  }

  if (window.Drupal?.eventBindings) {
    initialize();
  } else {
    document.addEventListener('drupal:event-bindings-ready', initialize, {
      once: true,
    });
  }
})(window.jQuery);
