/**
 * @file
 * Prototypes legacy delivery for the explicit event bridge test fixture.
 */

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
export function observeLegacyEvents(jquery, registry) {
  const subscriptions = new Map();

  return registry.observe({
    add(binding) {
      const { target, type, namespace, selector, listener } = binding;
      // Only exercise paths that do not produce a second native delivery:
      // a colon-delimited event, an anchor click, and a prevented form submit.
      const directActivation =
        !selector &&
        ((type === 'click' && target instanceof HTMLAnchorElement) ||
          (type === 'submit' && target instanceof HTMLFormElement));
      if (type !== 'compatibility:probe' && !directActivation) {
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
