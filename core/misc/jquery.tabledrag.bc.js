/**
 * @file
 * Adapts the legacy table interaction contracts for explicit consumers.
 * @param {Function} jquery
 *   The explicitly loaded library instance.
 * @deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the native
 *   table or row member, or native theme/interaction callback.
 */

((jquery) => {
  const { Drupal } = window;
  const deprecated = new Set();

  /**
   * Reports the first use of a legacy member or callback.
   *
   * @param {string} name
   *   The legacy contract.
   */
  function warn(name) {
    if (!deprecated.has(name)) {
      deprecated.add(name);
      window.Drupal.deprecationError({
        message: `${name} is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the native table or row member, or native theme/interaction callback.`,
      });
    }
  }

  /**
   * Installs a deprecated collection alias over a native member.
   *
   * @param {object} owner
   *   The constructor declaring the member.
   * @param {string} ownerName
   *   The constructor's public name.
   * @param {string} name
   *   The native member name.
   * @param {boolean} [list]
   *   Whether the member contains multiple nodes.
   *
   * @deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the
   *   native table or row member, or native theme/interaction callback.
   */
  function collectionAlias(owner, ownerName, name, list = false) {
    const wrapped = new WeakMap();
    const alias = `$${name}`;
    const label = `${ownerName}#${alias}`;
    Object.defineProperty(owner.prototype, alias, {
      configurable: true,
      get() {
        warn(label);
        const value = this[name];
        let cached = wrapped.get(this);
        if (!cached || cached.value !== value) {
          cached = { value, collection: jquery(value) };
          wrapped.set(this, cached);
        }
        return cached.collection;
      },
      set(collection) {
        warn(label);
        const value = list ? collection.toArray() : collection[0];
        this[name] = value;
        wrapped.set(this, { value, collection });
      },
    });
  }

  const events = new WeakMap();
  const nativeEvents = new WeakMap();

  /**
   * Adapts events only for assigned legacy overrides.
   *
   * Native overrides can set the function's nativeTableEvent property to true
   * before assignment. Saving a previous method retains its invocation behavior.
   *
   * @param {Function} owner
   *   The constructor holding the native method.
   * @param {string} method
   *   The method name.
   * @param {number} [eventIndex]
   *   The position of the event argument.
   *
   * @deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the
   *   native table or row member, or native theme/interaction callback.
   */
  function eventOverride(owner, method, eventIndex = 0) {
    const { prototype, eventCallbacks } = owner;
    const callbacks = new WeakMap();
    const current = prototype[method];
    const builtin = eventCallbacks[method];
    callbacks.set(prototype, function (...args) {
      const event = args[eventIndex];
      if (event instanceof jquery.Event) {
        args[eventIndex] =
          nativeEvents.get(event) ?? event.originalEvent ?? event;
      }
      return builtin.apply(this, args);
    });
    Object.defineProperty(prototype, method, {
      configurable: true,
      enumerable: true,
      get() {
        return callbacks.get(callbacks.has(this) ? this : prototype);
      },
      set(callback) {
        if (typeof callback !== 'function' || callback.nativeTableEvent) {
          callbacks.set(this, callback);
          return;
        }
        callbacks.set(this, function (...args) {
          const event = args[eventIndex];
          if (event instanceof Event) {
            warn(`The legacy event argument for ${method}`);
            if (!events.has(event)) {
              const legacyEvent = jquery.Event(event);
              if (event.type === 'resize' && !event.target) {
                legacyEvent.target = window;
                legacyEvent.currentTarget = window;
              }
              if (window.Drupal.tableSelect?.isSelectionChange?.(event)) {
                legacyEvent.isTrigger = 3;
              }
              events.set(event, legacyEvent);
              nativeEvents.set(legacyEvent, event);
            }
            args[eventIndex] = events.get(event);
          }
          return callback.apply(this, args);
        });
      },
    });
    if (current !== eventCallbacks[method]) {
      prototype[method] = current;
    }
    Object.values(owner).forEach((instance) => {
      if (instance instanceof owner && Object.hasOwn(instance, method)) {
        const callback = instance[method];
        delete instance[method];
        instance[method] = callback;
      }
    });
  }

  /**
   * Connects the drag adapter to the native table constructor.
   */
  function installTableDrag() {
    const { tableDrag } = window.Drupal;
    ['table', 'toggleWeightButton'].forEach((name) =>
      collectionAlias(tableDrag, 'Drupal.tableDrag', name),
    );

    Object.keys(tableDrag.eventCallbacks).forEach((method) =>
      eventOverride(tableDrag, method, method === 'getPointerOffset' ? 1 : 0),
    );

    const { themeContent } = tableDrag;
    tableDrag.themeContent = (markup) => {
      if (markup instanceof jquery) {
        warn('Returning a legacy collection from a table drag theme function');
        return themeContent([...markup]);
      }
      return themeContent(markup);
    };
  }

  /**
   * Connects responsive table aliases and the synthetic resize namespace.
   */
  function installResponsive() {
    const { TableResponsive } = window.Drupal;
    ['table', 'link'].forEach((name) =>
      collectionAlias(TableResponsive, 'Drupal.TableResponsive', name),
    );
    ['headers', 'revealedCells'].forEach((name) =>
      collectionAlias(TableResponsive, 'Drupal.TableResponsive', name, true),
    );
    [
      'eventhandlerEvaluateColumnVisibility',
      'eventhandlerToggleColumns',
    ].forEach((method) => eventOverride(TableResponsive, method));

    const resizeRefreshes = new WeakSet();
    window.addEventListener('drupal:tableresponsive-refreshed', () => {
      const event = jquery.Event('resize.tableresponsive');
      resizeRefreshes.add(event);
      jquery(window).trigger(event);
    });
    const subscriptions = new Map();
    const bindings = new WeakMap();
    const windowEvents = jquery(window);
    const resize = jquery.event.special.resize ?? {};
    jquery.event.special.resize = resize;
    const previousRemove = resize.remove;
    resize.remove = function (handle) {
      previousRemove?.call(this, handle);
      const binding = bindings.get(handle.handler);
      if (binding) {
        bindings.delete(handle.handler);
        subscriptions.delete(binding);
        warn('The legacy resize.tableresponsive removal');
        binding.stop();
      }
    };

    window.Drupal.eventBindings.observe({
      add(binding) {
        if (
          binding.target !== window ||
          binding.type !== 'resize' ||
          binding.namespace !== 'tableresponsive'
        ) {
          return;
        }
        const handler = (event) => {
          if (
            !event.isTrigger ||
            event.originalEvent ||
            resizeRefreshes.has(event)
          ) {
            return;
          }
          warn('The synthetic resize.tableresponsive trigger');
          let nativeEvent = nativeEvents.get(event);
          if (!nativeEvent) {
            nativeEvent = new Event('resize', { cancelable: true });
            nativeEvents.set(event, nativeEvent);
            events.set(nativeEvent, event);
          }
          binding.listener(nativeEvent);
        };
        bindings.set(handler, binding);
        subscriptions.set(binding, handler);
        windowEvents.on('resize.tableresponsive', handler);
      },
      remove(binding) {
        const handler = subscriptions.get(binding);
        if (handler) {
          bindings.delete(handler);
          subscriptions.delete(binding);
          windowEvents.off('resize.tableresponsive', handler);
        }
      },
    });
  }

  /**
   * Connects the Default Admin bulk actions adapter.
   */
  function installBulkActions() {
    const { DefaultAdminBulkActions } = window.Drupal;
    collectionAlias(
      DefaultAdminBulkActions,
      'Drupal.DefaultAdminBulkActions',
      'tabbable',
      true,
    );
    [
      'blurHandler',
      'focusHandler',
      'rowCheckboxHandler',
      'selectAllHandler',
    ].forEach((method) => eventOverride(DefaultAdminBulkActions, method));
  }

  /**
   * Accepts an explicitly passed legacy context in the table-select behavior.
   */
  function installTableSelect() {
    const { tableSelect } = window.Drupal.behaviors;
    const { attach } = tableSelect;
    tableSelect.attach = function (context, settings) {
      if (context instanceof jquery) {
        warn('The legacy tableSelect behavior context');
        context
          .toArray()
          .forEach((element) => attach.call(this, element, settings));
      } else {
        attach.call(this, context, settings);
      }
    };
  }

  if (Drupal?.tableDrag) {
    installTableDrag();
  } else {
    document.addEventListener('drupal:tabledrag-ready', installTableDrag, {
      once: true,
    });
  }
  if (Drupal?.TableResponsive) {
    installResponsive();
  } else {
    document.addEventListener(
      'drupal:tableresponsive-ready',
      installResponsive,
      { once: true },
    );
  }
  if (Drupal?.DefaultAdminBulkActions) {
    installBulkActions();
  } else {
    document.addEventListener('drupal:bulk-actions-ready', installBulkActions, {
      once: true,
    });
  }
  if (Drupal?.behaviors?.tableSelect) {
    installTableSelect();
  } else {
    document.addEventListener('drupal:tableselect-ready', installTableSelect, {
      once: true,
    });
  }
})(window.jQuery);
