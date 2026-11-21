/**
 * @file
 * Native form event binding and publication.
 */

((Drupal) => {
  const bindings = new Set();
  const pendingRemoval = new Set();
  const removalObserver = new MutationObserver(() => {
    pendingRemoval.forEach((binding) => {
      if (!binding.target.isConnected) {
        binding.stop();
      }
    });
  });

  /**
   * Binds a form interaction with an optional delegation selector.
   *
   * @internal
   */
  function listen(target, type, namespace, listener, selector) {
    const binding = {
      target,
      type,
      namespace,
      selector,
      listener,
      formInteraction: true,
      stop() {
        target.removeEventListener(type, handle);
        bindings.delete(binding);
        if (pendingRemoval.delete(binding) && !pendingRemoval.size) {
          removalObserver.disconnect();
        }
        unregister();
      },
    };
    const handle = (event) => {
      if (binding.handlesNativeEvent) {
        return;
      }
      if (!selector) {
        listener(event, target);
        return;
      }
      if (type === 'click' && event.button >= 1) {
        return;
      }
      const closest = event.target.closest?.(selector);
      if (closest && closest !== target && target.contains(closest)) {
        listener(event, closest);
      }
    };
    target.addEventListener(type, handle);
    const unregister = Drupal.eventBindings.register(binding);
    bindings.add(binding);
    return binding.stop;
  }

  /**
   * Releases form listeners when Drupal removes their target elements.
   */
  Drupal.behaviors.formEventBindings = {
    detach(context, settings, trigger) {
      if (trigger !== 'unload' || context.nodeType !== Node.ELEMENT_NODE) {
        return;
      }
      // Detach runs before a DOM update. Keep listeners on any retained wrapper
      // and release removed targets even when a swap is delayed.
      bindings.forEach((binding) => {
        const { target } = binding;
        if (target instanceof Node && context.contains(target)) {
          if (target.isConnected) {
            pendingRemoval.add(binding);
          } else {
            binding.stop();
          }
        }
      });
      if (pendingRemoval.size) {
        removalObserver.observe(document, { childList: true, subtree: true });
      }
    },
  };

  /**
   * Sends one bubbling form event from the original target.
   *
   * @internal
   */
  function publish(target, type, detail) {
    Drupal.eventBindings.publish(
      target,
      new CustomEvent(type, { detail, bubbles: true, cancelable: true }),
    );
  }

  /**
   * Connects native form events to Drupal's event registry.
   *
   * @internal
   */
  Drupal.formEvents = { listen, publish };
})(Drupal);
