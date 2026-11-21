/**
 * @file
 * Native form event binding and publication.
 */

((Drupal) => {
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
    return binding.stop;
  }

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
