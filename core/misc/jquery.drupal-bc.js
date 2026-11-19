/**
 * @file
 * Prototypes event delivery for the explicit event bridge test fixture.
 * @param {function(HTMLElement): object} instance
 *   The explicitly loaded jQuery instance.
 */

(function (instance) {
  /**
   * Observes the fixture's owned bindings using one explicit jQuery instance.
   * @param {function(HTMLElement): object} jquery
   *   The jQuery instance used by the fixture.
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
        // Only subscribe to the paths exercised by the fixture. A select has
        // no native change() method for jQuery's trigger to invoke.
        const selectChange =
          !selector && type === 'change' && target instanceof HTMLSelectElement;
        const directActivation =
          !selector &&
          ((type === 'click' && target instanceof HTMLAnchorElement) ||
            (type === 'submit' && target instanceof HTMLFormElement));
        if (
          type !== 'compatibility:probe' &&
          !directActivation &&
          !selectChange
        ) {
          return;
        }

        const name = namespace ? `${type}.${namespace}` : type;

        /**
         * Routes one synthetic invocation through the fixture operation.
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
            event.originalEvent ||
            (directActivation && event.target !== target)
          ) {
            return undefined;
          }

          // Forward cancellation to the active dispatch without creating or
          // dispatching a second DOM event. Return the result to jQuery.
          return listener.call(
            this,
            {
              type: event.type,
              target: event.target,
              currentTarget: event.currentTarget,
              delegateTarget: event.delegateTarget,
              get defaultPrevented() {
                return event.isDefaultPrevented();
              },
              preventDefault: () => event.preventDefault(),
              stopPropagation: () => event.stopPropagation(),
              stopImmediatePropagation: () => event.stopImmediatePropagation(),
            },
            ...args,
          );
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
   *   The jQuery instance opting in to the fixture.
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

  const connections = new WeakMap();

  /**
   * Connects the fixture to one explicitly selected jQuery instance.
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
      const stop = observeLegacyEvents(jquery, window.Drupal.eventBindings);
      const disconnect = () => {
        if (connections.get(jquery) === disconnect) {
          stop();
          connections.delete(jquery);
        }
      };
      connections.set(jquery, disconnect);
    }
    return connections.get(jquery);
  }

  /**
   * Makes the fixture API available and connects the selected instance.
   */
  function initialize() {
    const registry = window.Drupal.eventBindings;
    registry.legacyFixture = registry.legacyFixture ?? {
      connect,
      observeLegacyEvents,
      observeNativePayloads,
    };
    registry.legacyFixture.connect(instance);
  }

  if (window.Drupal?.eventBindings) {
    initialize();
  } else {
    document.addEventListener('drupal:event-bindings-ready', initialize, {
      once: true,
    });
  }
})(window.jQuery);
