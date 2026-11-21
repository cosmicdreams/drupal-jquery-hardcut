/**
 * @file
 * Adapts Drupal form interactions for explicitly loaded jQuery.
 *
 * @deprecated in drupal:12.0.0 and is removed from drupal:13.0.0.
 *   See https://www.drupal.org/node/CHANGE_RECORD.
 */

((jquery) => {
  function install() {
    const { Drupal } = window;
    const subscriptions = new Map();
    const handlers = new Map();

    // A contrib .off() removes its original delegated binding as before.
    // Compose with any other compatibility handlers for click.
    const special =
      jquery.event.special.click || (jquery.event.special.click = {});
    const remove = special.remove;
    special.remove = function (handle) {
      try {
        remove?.call(this, handle);
      } finally {
        const binding = handlers.get(handle.guid);
        if (binding?.target === this) {
          handlers.delete(handle.guid);
          binding.stop();
        }
      }
    };

    let warned = false;
    Drupal.eventBindings.observe({
      add(binding) {
        const { target, selector, type, namespace, listener } = binding;
        if (!binding.detailsAria) {
          return;
        }
        const element = jquery(target);
        const name = `${type}.${namespace}`;
        const deliver = function (event) {
          if (event.isTrigger && !warned) {
            warned = true;
            Drupal.deprecationError({
              message:
                'The jQuery synthetic trigger for details summary clicks is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the native click event. See https://www.drupal.org/node/CHANGE_RECORD.',
            });
          }
          listener(this);
        };
        const slot = Drupal.eventBindings.claimLegacyEvent?.(
          jquery,
          binding,
          deliver,
        );
        const handler = slot?.handler ?? deliver;
        if (!slot) {
          element.on(name, selector, handler);
        }
        // On mixed pages the old delegation slot observes any prior legacy
        // cancellation, including when no native click follows the trigger.
        binding.handlesNativeEvent = true;
        handlers.set(handler.guid, binding);
        subscriptions.set(binding, () => {
          const unbind = handlers.delete(handler.guid);
          if (slot) {
            slot.remove(unbind);
          } else if (unbind) {
            element.off(name, selector, handler);
          }
          delete binding.handlesNativeEvent;
        });
      },
      remove(binding) {
        subscriptions.get(binding)?.();
        subscriptions.delete(binding);
      },
    });
  }

  if (window.Drupal?.eventBindings) {
    install();
  } else {
    document.addEventListener('drupal:event-bindings-ready', install, {
      once: true,
    });
  }
})(window.jQuery);
