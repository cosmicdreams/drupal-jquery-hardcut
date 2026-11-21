/**
 * @file
 * Adapts Drupal form interactions for explicitly loaded jQuery.
 *
 * @param {Function} jquery
 *   The explicitly loaded library instance.
 *
 * @deprecated in drupal:12.0.0 and is removed from drupal:13.0.0.
 *   See https://www.drupal.org/node/CHANGE_RECORD.
 */

((jquery) => {
  if (typeof jquery !== 'function') {
    return;
  }

  const warned = new Set();

  /**
   * Reports the first explicit use of a deprecated contract.
   */
  function warn(contract, migration) {
    if (!warned.has(contract)) {
      warned.add(contract);
      window.Drupal.deprecationError({
        message: `${contract} is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. ${migration} See https://www.drupal.org/node/CHANGE_RECORD.`,
      });
    }
  }

  /**
   * Installs legacy bindings and removes native counterparts on legacy off().
   */
  function install() {
    const { Drupal } = window;
    const subscriptions = new Map();
    const handlers = new Map();
    const observed = new Set();

    function observeRemoval(type) {
      if (observed.has(type)) {
        return;
      }
      observed.add(type);
      const special =
        jquery.event.special[type] || (jquery.event.special[type] = {});
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
    }

    Drupal.eventBindings.observe({
      add(binding) {
        const { target, selector, type, namespace, listener } = binding;
        const { detailsAria, tabbingFocusTrap, navTabs } = binding;
        if (!detailsAria && !tabbingFocusTrap && !navTabs) {
          return;
        }
        const contract = detailsAria
          ? 'details summary clicks'
          : navTabs
            ? 'navigation tabs'
            : 'tabbing focus traps';
        observeRemoval(type);
        const element = jquery(target);
        const name = `${type}.${namespace}`;
        const deliver = function (event) {
          if (event.isTrigger) {
            warn(
              `The jQuery synthetic trigger for ${contract}`,
              `Use the native ${type} event.`,
            );
          }
          if (type === 'click') {
            listener(this);
          } else {
            const native =
              !event.isTrigger && event.originalEvent instanceof Event
                ? event.originalEvent
                : new KeyboardEvent(type, {
                    cancelable: true,
                    key: event.key,
                    shiftKey: event.shiftKey,
                  });
            listener(native);
            if (native.defaultPrevented) {
              event.preventDefault();
            }
          }
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

  const tabbingMigration = 'Use native context members and element lists.';
  const tabbingEvents = [
    'drupalTabbingConstrained',
    'drupalTabbingContextActivated',
    'drupalTabbingContextDeactivated',
    'drupalTabbingContextReleased',
  ];

  /**
   * Adds the original context argument to existing tabbing lifecycle handlers.
   */
  function installTabbingPublications() {
    const publications = new WeakSet();
    window.Drupal.eventBindings.observePublications((event) => {
      if (tabbingEvents.includes(event.type)) {
        publications.add(event);
        return () => publications.delete(event);
      }
      return undefined;
    });
    tabbingEvents.forEach((type) => {
      const special =
        jquery.event.special[type] || (jquery.event.special[type] = {});
      const previous = special.handle;
      special.handle = function (event, ...args) {
        const original = event.originalEvent;
        if (!event.isTrigger && publications.has(original)) {
          args = [original.detail];
        }
        return (previous || event.handleObj.handler).call(this, event, ...args);
      };
    });
  }

  /**
   * Adds legacy collection accessors and methods when tabbing is present.
   */
  function installTabbingMembers() {
    const manager = window.Drupal?.tabbingManager;
    if (!manager?.contextPrototype) {
      return;
    }

    ['tabbableElements', 'disabledElements'].forEach((name) => {
      const cached = new WeakMap();
      const legacyName = `$${name}`;
      const contract = `TabbingContext#${legacyName}`;
      Object.defineProperty(manager.contextPrototype, legacyName, {
        configurable: true,
        get() {
          warn(contract, tabbingMigration);
          const value = this[name];
          let entry = cached.get(this);
          if (!entry || entry.value !== value) {
            entry = { value, collection: jquery(value) };
            cached.set(this, entry);
          }
          return entry.collection;
        },
        set(input) {
          warn(contract, tabbingMigration);
          const collection = input instanceof jquery ? input : jquery(input);
          const value = collection.toArray();
          this[name] = value;
          cached.set(this, { value, collection });
        },
      });
    });

    const nativeConstrain = manager.constrain;
    manager.constrain = function (input, options) {
      if (typeof input === 'string' && input) {
        let elements;
        try {
          elements = document.querySelectorAll(input);
        } catch (error) {
          if (error.name !== 'SyntaxError') {
            throw error;
          }
        }
        if (elements) {
          return this.constrainElements(elements, options);
        }
      } else if (!(input instanceof jquery)) {
        return nativeConstrain.call(this, input || undefined, options);
      }
      warn(
        'The jQuery input to Drupal.tabbingManager.constrain',
        tabbingMigration,
      );
      return this.constrainElements(jquery(input).toArray(), options);
    };

    ['recordTabindex', 'restoreTabindex'].forEach((legacyName) => {
      const nativeName = `${legacyName}Element`;
      const native = manager[nativeName];
      const contract = `Drupal.tabbingManager.${legacyName}`;
      const defaultMethod = function (collection, level) {
        warn(contract, tabbingMigration);
        return native.call(this, jquery(collection)[0], level);
      };
      let method = manager[legacyName];
      Object.defineProperty(manager, legacyName, {
        configurable: true,
        get() {
          return method || defaultMethod;
        },
        set(callback) {
          warn(contract, tabbingMigration);
          method = callback;
          manager[nativeName] = callback
            ? function (element, level) {
                return callback.call(this, jquery(element), level);
              }
            : native;
        },
      });
      if (method) {
        manager[legacyName] = method;
      }
    });
  }

  if (window.Drupal?.eventBindings) {
    install();
    installTabbingPublications();
  } else {
    document.addEventListener(
      'drupal:event-bindings-ready',
      () => {
        install();
        installTabbingPublications();
      },
      { once: true },
    );
  }
  if (window.Drupal?.tabbingManager) {
    installTabbingMembers();
  } else {
    document.addEventListener(
      'drupal:tabbing-manager-ready',
      installTabbingMembers,
      {
        once: true,
      },
    );
  }
})(window.jQuery);
