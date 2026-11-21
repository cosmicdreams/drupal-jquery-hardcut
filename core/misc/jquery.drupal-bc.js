/**
 * @file
 * Adapts owned events for explicitly connected jQuery instances.
 * @param {function(HTMLElement): object} instance
 *   The explicitly loaded jQuery instance.
 */

(function (instance) {
  const foreignTriggers = [];

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
    const subscriptions = new Map();

    return registry.observe({
      add(binding) {
        const { target, type, namespace, selector, listener } = binding;
        // The primary Ajax adapter owns its jQuery dispatch slots. Independent
        // instances opt in to synthetic delivery through this bridge.
        const foreignAjax = binding.ajax && jquery !== instance;
        if (binding.ajax && !foreignAjax) {
          return;
        }
        // A select has no native change() method for jQuery's trigger to invoke.
        const selectChange =
          !selector && type === 'change' && target instanceof HTMLSelectElement;
        const directActivation =
          !selector &&
          ((type === 'click' && target instanceof HTMLAnchorElement) ||
            (type === 'submit' && target instanceof HTMLFormElement));
        if (
          !foreignAjax &&
          type !== 'compatibility:probe' &&
          !directActivation &&
          !selectChange
        ) {
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
            !event.isTrigger ||
            (!foreignAjax &&
              (event.originalEvent ||
                (directActivation && event.target !== target)))
          ) {
            return undefined;
          }

          if (foreignAjax) {
            return listener.call(this, event, ...args);
          }

          // Preserve jQuery's event metadata and forward cancellation to the
          // current dispatch without firing another event.
          const projected = Object.create(event, {
            isTrigger: { value: event.isTrigger },
            defaultPrevented: { get: () => event.isDefaultPrevented() },
            preventDefault: { value: () => event.preventDefault() },
            stopPropagation: { value: () => event.stopPropagation() },
            stopImmediatePropagation: {
              value: () => event.stopImmediatePropagation(),
            },
          });
          return listener.call(this, projected, ...args);
        }

        const element = jquery(target);
        element.on(name, selector, deliver);
        subscriptions.set(binding, () => element.off(name, selector, deliver));
      },
      remove(binding) {
        subscriptions.get(binding)?.();
        subscriptions.delete(binding);
      },
    });
  }

  /**
   * Projects explicitly marked native events at jQuery's handler dispatch.
   * @param {object} jquery
   *   The explicitly connected jQuery instance.
   * @param {string} type
   *   The unmapped event type. Install one projection per instance and type.
   * @param {WeakMap} payloads
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
    const projections = new Map();
    const stop = registry.observePublications((event) => {
      const { type, detail } = event;
      const state = type.startsWith('state:');
      if ((!state && type !== 'columnschange') || payloads.has(event)) {
        return undefined;
      }

      let payload;
      if (state) {
        const properties = {};
        for (const field of ['value', 'oldValue', 'trigger']) {
          const value =
            detail && Object.hasOwn(detail, field) ? detail[field] : undefined;
          // jQuery's event constructor skips undefined properties.
          if (value !== undefined) {
            properties[field] = value;
          }
        }
        payload = { properties };
      } else {
        payload = { args: [Boolean(detail?.displayWeight)] };
      }

      if (!projections.has(type)) {
        projections.set(type, observeNativePayloads(jquery, type, payloads));
      }
      payloads.set(event, payload);
      return () => payloads.delete(event);
    });

    return () => {
      stop();
      projections.forEach((disconnect) => disconnect());
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
      const stopTriggers =
        jquery !== instance && observeForeignTriggers(jquery);
      const stopBindings = observeLegacyEvents(jquery, registry);
      const stopPublications = observeOwnedPublications(jquery, registry);
      const disconnect = () => {
        if (connections.get(jquery) === disconnect) {
          if (stopTriggers) {
            stopTriggers();
          }
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
    registry.isForeignDefaultAction =
      registry.isForeignDefaultAction || isForeignDefaultAction;
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
