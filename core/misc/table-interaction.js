/**
 * @file
 * Shared event handling for table interactions.
 */

((Drupal) => {
  const tableListeners = new WeakMap();
  const pending = new Set();
  const observer = new MutationObserver((records) => {
    if (records.some(({ removedNodes }) => removedNodes.length)) {
      releaseDisconnected();
    }
  });

  /**
   * Releases pending bindings after their owner or target leaves the page.
   */
  function releaseDisconnected() {
    pending.forEach((binding) => {
      const { owner, target } = binding;
      if (
        !owner.isConnected ||
        (target instanceof Node && !target.isConnected)
      ) {
        binding.stop();
      }
    });
  }

  Drupal.behaviors.tableInteraction = {
    attach: releaseDisconnected,

    detach(context, settings, trigger) {
      if (trigger !== 'unload' || !context?.querySelectorAll) {
        return;
      }
      const owners = new Set(context.querySelectorAll('table, form'));
      for (let element = context; element; element = element.parentElement) {
        if (tableListeners.has(element)) {
          owners.add(element);
        }
      }
      const wasIdle = !pending.size;
      owners.forEach((owner) => {
        tableListeners.get(owner)?.forEach((binding) => {
          const { target } = binding;
          if (
            context.contains(owner) ||
            (target instanceof Node && context.contains(target))
          ) {
            pending.add(binding);
          }
        });
      });
      if (pending.size) {
        // Unload can keep a dialog in place or precede a delayed removal.
        if (wasIdle) {
          observer.observe(document, { childList: true, subtree: true });
        }
        queueMicrotask(releaseDisconnected);
      }
    },
  };

  /**
   * Native operations shared by the three table libraries.
   *
   * @internal
   */
  Drupal.tableInteraction = {
    releaseDisconnected,

    /**
     * Binds a listener owned by a table or form.
     *
     * @param {HTMLElement} owner
     *   The table or form that owns the listener.
     * @param {EventTarget} target
     *   The listener target.
     * @param {string} type
     *   The event type.
     * @param {Function} listener
     *   The native handler.
     * @return {Function}
     *   Releases the listener before its table or target unloads.
     */
    listen(owner, target, type, listener) {
      target.addEventListener(type, listener);
      return this.own(owner, target, () => {
        target.removeEventListener(type, listener);
      });
    },

    /**
     * Removes adjacent controls left behind when a table is replaced.
     *
     * @param {HTMLTableElement} table
     *   The unloading table.
     * @param {Node[]} controls
     *   Nodes inserted beside the table or its wrapper.
     */
    removeOrphanedControls(table, controls) {
      if (!table.isConnected) {
        controls.forEach((node) => {
          if (node.isConnected) {
            node.remove();
          }
        });
      }
    },

    /**
     * Inserts select-all markup in headers that need a checkbox.
     *
     * @param {HTMLElement[]} headers
     *   The headers receiving a checkbox.
     * @param {string|Node} markup
     *   The themed checkbox.
     * @param {string} title
     *   The checkbox label.
     */
    insertCheckboxes(headers, markup, title) {
      let content = markup;
      if (typeof markup === 'string') {
        const template = document.createElement('template');
        template.innerHTML = markup.trim();
        content = template.content;
      }
      if (!(content instanceof Node)) {
        return;
      }
      headers.forEach((header, index) => {
        const fragment =
          index === headers.length - 1 ? content : content.cloneNode(true);
        header.prepend(fragment);
        const checkbox = header.querySelector('input[type="checkbox"]');
        if (checkbox) {
          checkbox.title = title;
        }
      });
    },

    /**
     * Releases an owned listener when its table or target unloads.
     *
     * @param {HTMLElement} owner
     *   The table or form that owns the listener.
     * @param {EventTarget} target
     *   The listener target.
     * @param {Function} stop
     *   Removes the listener and releases any associated state.
     * @return {Function}
     *   Releases the binding immediately.
     */
    own(owner, target, stop) {
      if (!tableListeners.has(owner)) {
        tableListeners.set(owner, new Set());
      }
      const listeners = tableListeners.get(owner);
      const binding = {
        owner,
        target,
        stop() {
          if (pending.delete(binding) && !pending.size) {
            observer.disconnect();
          }
          if (listeners.delete(binding)) {
            stop();
          }
        },
      };
      listeners.add(binding);
      return binding.stop;
    },
  };
})(Drupal);
