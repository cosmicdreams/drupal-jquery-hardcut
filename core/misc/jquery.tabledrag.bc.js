/**
 * @file
 * Adapts the legacy table interaction contracts for explicit consumers.
 * @param {Function} jquery
 *   The explicitly loaded library instance.
 * @deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the native
 *   table or row member, or native theme/interaction callback.
 */

((jquery) => {
  if (typeof jquery !== 'function') {
    return;
  }

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
  function collectionAlias(
    owner,
    ownerName,
    name,
    list = false,
    prepare = (collection) => collection,
  ) {
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
          cached = { value, collection: prepare(jquery(value)) };
          wrapped.set(this, cached);
        }
        return cached.collection;
      },
      set(input) {
        warn(label);
        const collection = input instanceof jquery ? input : jquery(input);
        const value = list
          ? collection.toArray()
          : input == null
            ? input
            : collection[0];
        this[name] = value;
        wrapped.set(this, { value, collection: prepare(collection) });
      },
    });
  }

  const events = new WeakMap();
  const nativeEvents = new WeakMap();
  const eventConstructors = {
    blur: FocusEvent,
    focus: FocusEvent,
    click: MouseEvent,
    mousedown: MouseEvent,
    mousemove: MouseEvent,
    mouseup: MouseEvent,
    keydown: KeyboardEvent,
    keypress: KeyboardEvent,
    pointerdown: PointerEvent,
    pointermove: PointerEvent,
    pointerup: PointerEvent,
    touchstart: window.TouchEvent,
    touchmove: window.TouchEvent,
    touchend: window.TouchEvent,
  };

  /**
   * Normalizes a legacy event without dispatching it again through the DOM.
   *
   * @param {Event|object} event
   *   The event passed to a native callback.
   * @return {Event}
   *   The underlying native event or the synthetic event's native view.
   */
  function toNativeEvent(event) {
    if (!(event instanceof jquery.Event)) {
      return event;
    }
    if (nativeEvents.has(event) || event.originalEvent instanceof Event) {
      return nativeEvents.get(event) ?? event.originalEvent;
    }

    const Constructor = eventConstructors[event.type] ?? Event;
    const native = new Constructor(event.type, { cancelable: true });
    [
      'target',
      'currentTarget',
      'relatedTarget',
      'key',
      'keyCode',
      'which',
      'shiftKey',
      'ctrlKey',
      'altKey',
      'metaKey',
      'clientX',
      'clientY',
      'pageX',
      'pageY',
      'button',
      'buttons',
      'pointerType',
      'touches',
      'changedTouches',
    ].forEach((property) => {
      const fallback = native[property];
      Object.defineProperty(native, property, {
        get: () => event[property] ?? fallback,
      });
    });
    Object.defineProperties(native, {
      defaultPrevented: { get: () => event.isDefaultPrevented() },
      preventDefault: { value: () => event.preventDefault() },
      stopPropagation: { value: () => event.stopPropagation() },
      stopImmediatePropagation: {
        value: () => event.stopImmediatePropagation(),
      },
    });
    events.set(native, event);
    nativeEvents.set(event, native);
    return native;
  }

  /**
   * Runs an explicitly registered legacy override at the compatibility boundary.
   *
   * @param {Function} callback
   *   The override.
   * @param {object} context
   *   The table interaction instance.
   * @param {Array} args
   *   The invocation arguments.
   * @param {number} index
   *   The event argument position.
   * @param {string} method
   *   The method name.
   * @return {*}
   *   The callback result.
   */
  function invoke(callback, context, args, index, method) {
    const event = args[index];
    if (callback.legacyTableEvent && event instanceof Event) {
      warn(`The legacy event argument for ${method}`);
      if (!events.has(event)) {
        const legacyEvent = jquery.Event(event);
        if (window.Drupal.tableSelect?.isSelectionChange?.(event)) {
          legacyEvent.isTrigger = 3;
        }
        events.set(event, legacyEvent);
        nativeEvents.set(legacyEvent, event);
      }
      args[index] = events.get(event);
    } else if (!callback.legacyTableEvent) {
      args[index] = toNativeEvent(event);
    }
    return callback.apply(context, args);
  }

  /**
   * Observes table-owned gesture bindings for legacy synthetic delivery.
   */
  function installGestures() {
    const subscriptions = new Map();
    window.Drupal.eventBindings.observe({
      add(binding) {
        const { target, type, listener } = binding;
        if (
          !binding.tableInteraction ||
          (type === 'click' && target instanceof HTMLAnchorElement)
        ) {
          return;
        }
        const element = jquery(target);
        const deliver = function (event) {
          if (!event.isTrigger || event.originalEvent) {
            return undefined;
          }
          // Full click triggers on checkable inputs already use browser activation.
          if (
            event.isTrigger === 3 &&
            type === 'click' &&
            target.matches?.('input:is([type="checkbox"], [type="radio"])')
          ) {
            return undefined;
          }
          warn(`The synthetic ${type} trigger for table interactions`);
          return listener.call(this, toNativeEvent(event));
        };
        element.on(type, deliver);
        subscriptions.set(binding, () => element.off(type, deliver));
      },
      remove(binding) {
        subscriptions.get(binding)?.();
        subscriptions.delete(binding);
      },
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
    collectionAlias(TableResponsive, 'Drupal.TableResponsive', 'table');
    collectionAlias(
      TableResponsive,
      'Drupal.TableResponsive',
      'link',
      false,
      responsiveLink,
    );
    ['headers', 'revealedCells'].forEach((name) =>
      collectionAlias(TableResponsive, 'Drupal.TableResponsive', name, true),
    );
    const refreshes = new WeakSet();
    TableResponsive.refresh = () => {
      const event = jquery.Event('resize.tableresponsive');
      refreshes.add(event);
      jquery(window).trigger(event);
    };
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
          if (!event.isTrigger || event.originalEvent) {
            return undefined;
          }
          if (!refreshes.has(event)) {
            warn('The synthetic resize.tableresponsive trigger');
          }
          return binding.listener(toNativeEvent(event));
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
   * Keeps the deprecated button data in sync with the native pegged value.
   *
   * @param {object} collection
   *   The responsive toggle collection.
   * @return {object}
   *   The same collection with pegged data access.
   */
  function responsiveLink(collection) {
    const link = collection[0];
    if (!link || !link.dataset || Object.hasOwn(collection, 'data')) {
      return collection;
    }
    const { data } = collection;
    if (link.dataset.pegged === undefined) {
      const pegged = data.call(collection, 'pegged');
      if (pegged !== undefined) {
        link.dataset.pegged = pegged;
      }
    }
    collection.data = function (key, value) {
      if (key === 'pegged') {
        if (value === undefined) {
          const pegged = link.dataset.pegged;
          return pegged === '0' || pegged === '1' ? Number(pegged) : pegged;
        }
        link.dataset.pegged = value;
      } else if (
        key &&
        typeof key === 'object' &&
        Object.hasOwn(key, 'pegged')
      ) {
        link.dataset.pegged = key.pegged;
      } else if (key === undefined) {
        const values = data.call(this);
        const pegged = this.data('pegged');
        if (pegged !== undefined) {
          values.pegged = pegged;
        }
        return values;
      }
      return data.apply(this, arguments);
    };
    return collection;
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
  }

  /**
   * Accepts an explicitly passed legacy collection context.
   *
   * @param {string} name
   *   The behavior name.
   */
  function installContext(name) {
    const behavior = window.Drupal.behaviors[name];
    const { attach } = behavior;
    behavior.attach = function (context, settings) {
      if (context instanceof jquery) {
        warn(`The legacy ${name} behavior context`);
        context
          .toArray()
          .forEach((element) => attach.call(this, element, settings));
      } else {
        attach.call(this, context, settings);
      }
    };
  }

  /**
   * Normalizes the deprecated checkbox theme return.
   */
  function installTableSelect() {
    installContext('tableSelect');
    const select = window.Drupal.tableSelect;
    const { checkboxContent } = select;
    select.checkboxContent = () => {
      const content = checkboxContent();
      if (content instanceof jquery) {
        warn('Returning a legacy collection from the checkbox theme function');
        const fragment = document.createDocumentFragment();
        fragment.append(...content);
        return fragment;
      }
      return content;
    };
  }

  function installEventAdapter() {
    window.Drupal.tableInteraction.invokeEventCallback = invoke;
  }

  if (Drupal?.tableInteraction) {
    installEventAdapter();
  } else {
    document.addEventListener(
      'drupal:table-interaction-ready',
      installEventAdapter,
      { once: true },
    );
  }

  if (Drupal?.eventBindings) {
    installGestures();
  } else {
    document.addEventListener('drupal:event-bindings-ready', installGestures, {
      once: true,
    });
  }

  if (Drupal?.behaviors?.adminTableDrag) {
    installContext('adminTableDrag');
  } else {
    document.addEventListener(
      'drupal:admin-tabledrag-ready',
      () => installContext('adminTableDrag'),
      { once: true },
    );
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
