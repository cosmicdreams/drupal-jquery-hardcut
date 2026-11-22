/**
 * @file
 * Native form event binding and publication.
 */

((Drupal) => {
  const bindings = new Set();
  const pendingUnloads = new Set();

  function disconnectIfIdle() {
    if (!pendingUnloads.size) {
      removalObserver.disconnect();
    }
  }

  function settleBatch(batch) {
    batch.bindings.forEach((binding) => {
      if (!binding.target.isConnected) {
        binding.stop();
      }
    });
    pendingUnloads.delete(batch);
    disconnectIfIdle();
  }

  /**
   * Checks whether a mutation record removed part of an unloaded batch.
   *
   * The record removes a tracked target, or a node holding the context or a
   * target, even if that node returns. It may also replace the content of a
   * tracked target that stays. Other removals inside the context, such as an
   * Ajax throbber leaving, do not settle the batch.
   * @param {MutationRecord} record
   *   A childList mutation record.
   * @param {object} batch
   *   A pending unload batch with its context and bindings.
   * @return {boolean}
   *   Whether the record removed part of the batch.
   */
  function removesFromBatch(record, batch) {
    if (!record.removedNodes.length) {
      return false;
    }
    const targets = Array.from(batch.bindings, ({ target }) => target);
    if (targets.includes(record.target)) {
      return true;
    }
    return Array.from(record.removedNodes).some(
      (node) =>
        node.contains(batch.context) ||
        targets.some((target) => node.contains(target)),
    );
  }

  // Settle a batch once the unload's update removes a tracked target, whether
  // it leaves, stays with new content or moves. Unrelated insertions and
  // removals, such as an Ajax throbber around a delayed swap, keep it.
  const removalObserver = new MutationObserver((records) => {
    Array.from(pendingUnloads).forEach((batch) => {
      if (
        Array.from(batch.bindings).some(({ target }) => !target.isConnected) ||
        records.some((record) => removesFromBatch(record, batch))
      ) {
        settleBatch(batch);
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
      reservesLegacySlot: true,
      stop() {
        target.removeEventListener(type, handle);
        bindings.delete(binding);
        pendingUnloads.forEach((batch) => {
          batch.bindings.delete(binding);
          if (!batch.bindings.size) {
            pendingUnloads.delete(batch);
          }
        });
        disconnectIfIdle();
        unregister();
      },
    };
    const handle = (event) => {
      if (binding.handlesNativeEvent?.(event)) {
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
    attach(context) {
      if (!pendingUnloads.size) {
        return;
      }
      Array.from(pendingUnloads).forEach((batch) => {
        if (
          !batch.context.isConnected ||
          context === document ||
          (context instanceof Node &&
            (context.contains(batch.context) ||
              batch.context.contains(context)))
        ) {
          settleBatch(batch);
        }
      });
    },
    detach(context, settings, trigger) {
      if (trigger !== 'unload' || context.nodeType !== Node.ELEMENT_NODE) {
        return;
      }
      // Detach runs before a DOM update. Keep listeners on any retained wrapper
      // and release removed targets once the unload mutation settles.
      const batchBindings = new Set();
      bindings.forEach((binding) => {
        const { target } = binding;
        if (target instanceof Node && context.contains(target)) {
          if (target.isConnected) {
            batchBindings.add(binding);
          } else {
            binding.stop();
          }
        }
      });
      if (batchBindings.size) {
        pendingUnloads.add({ context, bindings: batchBindings });
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
