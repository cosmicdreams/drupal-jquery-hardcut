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
  const documentGestures = [
    'mousemove',
    'mouseup',
    'pointermove',
    'pointerup',
    'touchmove',
    'touchend',
  ];
  const sharedHandlers = new WeakMap();

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
  const nativeResizeSlots = new WeakSet();
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
    if (nativeEvents.has(event)) {
      return nativeEvents.get(event);
    }
    if (!event.isTrigger && event.originalEvent instanceof Event) {
      const native = event.originalEvent;
      events.set(native, event);
      nativeEvents.set(event, native);
      return native;
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
        get: () =>
          event[property] ?? event.originalEvent?.[property] ?? fallback,
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
   * Delivers the event shape selected by the callback registration.
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
   * @param {boolean} builtin
   *   Whether this is the original implementation.
   * @return {*}
   *   The callback result.
   */
  function invoke(callback, context, args, index, method, builtin) {
    const event = args[index];
    const legacy = !builtin && callback.nativeTableEvent !== true;
    if (legacy && event instanceof Event) {
      warn(`The legacy event argument for ${method}`);
      if (!events.has(event)) {
        const legacyEvent = jquery.Event(event);
        if (
          window.Drupal.tableInteraction.tableSelect?.isSelectionChange(event)
        ) {
          legacyEvent.isTrigger = 3;
        }
        events.set(event, legacyEvent);
        nativeEvents.set(legacyEvent, event);
      }
      args[index] = events.get(event);
    } else if (!legacy) {
      args[index] = toNativeEvent(event);
    }
    return callback.apply(context, args);
  }

  /**
   * Reads the registered handlers for one jQuery dispatch type.
   *
   * @param {EventTarget} target
   *   The dispatch target.
   * @param {string} type
   *   The event type.
   * @return {object[]}
   *   The handler descriptors in dispatch order.
   */
  function jqueryHandlers(target, type) {
    return jquery._data(target, 'events')?.[type] ?? [];
  }

  /**
   * Updates shared delivery when jQuery's handler list changes.
   */
  function installSharedDispatch() {
    const previousBindings = new Map();

    function update(target, type) {
      const handlers = jqueryHandlers(target, type);
      const bindings = handlers
        .map(({ handler }) => sharedHandlers.get(handler))
        .filter(Boolean);
      previousBindings.get(type)?.forEach((binding) => {
        binding.nativeActive = true;
      });
      if (!bindings.length) {
        previousBindings.delete(type);
        return;
      }
      previousBindings.set(type, bindings);

      const mixed = handlers.length !== bindings.length;
      // Keep both dispatch slots so later native listeners cannot overtake
      // table delivery when legacy subscribers arrive or leave.
      // Without legacy subscribers, jQuery still dispatches the event and the
      // bridge callback yields to the native listener.
      bindings.forEach((binding) => {
        binding.nativeActive = !mixed || nativeResizeSlots.has(binding);
      });
    }

    // Track the completed registration even if a plugin replaces the special
    // event hooks for a gesture.
    ['add', 'remove'].forEach((method) => {
      const original = jquery.event[method];
      jquery.event[method] = function (target, ...args) {
        const result = original.call(this, target, ...args);
        if (target === window) {
          update(target, 'resize');
        } else if (target === document) {
          documentGestures.forEach((type) => update(target, type));
        }
        return result;
      };
    });
  }

  /**
   * Delivers a real event in jQuery's queue and mirrors native cancellation.
   *
   * @param {object} binding
   *   The table's event binding.
   * @param {object} event
   *   The jQuery event for this dispatch.
   * @return {*}
   *   The table listener's result.
   */
  function deliverShared(binding, event) {
    if (binding.nativeActive) {
      return undefined;
    }

    const native = toNativeEvent(event);
    const flags = {
      preventDefault: 'isDefaultPrevented',
      stopPropagation: 'isPropagationStopped',
      stopImmediatePropagation: 'isImmediatePropagationStopped',
    };
    const methods = Object.keys(flags).filter(
      (method) =>
        Object.isExtensible(native) &&
        Object.getOwnPropertyDescriptor(native, method)?.configurable !== false,
    );
    const descriptors = methods.map((method) =>
      Object.getOwnPropertyDescriptor(native, method),
    );
    const stopped = () => true;
    methods.forEach((method) => {
      const original = native[method];
      Object.defineProperty(native, method, {
        configurable: true,
        writable: true,
        value() {
          original.call(this);
          event[flags[method]] = stopped;
          if (method === 'stopImmediatePropagation') {
            event.isPropagationStopped = stopped;
          }
        },
      });
    });
    try {
      return binding.listener.call(binding.target, native);
    } finally {
      if (native.defaultPrevented) {
        event.isDefaultPrevented = stopped;
      }
      methods.forEach((method, index) => {
        if (descriptors[index]) {
          Object.defineProperty(native, method, descriptors[index]);
        } else {
          delete native[method];
        }
      });
    }
  }

  /**
   * Observes table-owned gesture bindings for legacy synthetic delivery.
   */
  function installGestures() {
    const subscriptions = new Map();
    let replaying = true;
    window.Drupal.eventBindings.observe({
      add(binding) {
        const { target, type, listener } = binding;
        if (!binding.tableInteraction) {
          return;
        }
        const anchorClick =
          type === 'click' && target instanceof HTMLAnchorElement;
        const windowResize = target === window && type === 'resize';
        const shared =
          windowResize ||
          (target === document && documentGestures.includes(type));
        const element = jquery(target);
        const deliver = function (event) {
          const { originalEvent } = event;
          // The selection bridge marks native changes for legacy observers.
          // Leave their delivery to the native listeners.
          if (
            !event.isTrigger ||
            (type === 'change' &&
              window.Drupal.tableInteraction.tableSelect?.isSelectionChange(
                originalEvent,
              ))
          ) {
            return shared ? deliverShared(binding, event) : undefined;
          }
          // The general Drupal bridge forwards direct clicks without a source event.
          if (anchorClick && event.target === target && !originalEvent) {
            return undefined;
          }
          warn(`The synthetic ${type} trigger for table interactions`);
          // A synthetic touch event can supply touch data without DOM methods.
          if (originalEvent && !(originalEvent instanceof Event)) {
            event.isSimulated = true;
          }
          return listener.call(this, toNativeEvent(event));
        };
        if (shared) {
          if (windowResize && replaying) {
            nativeResizeSlots.add(binding);
          }
          sharedHandlers.set(deliver, binding);
        }
        element.on(type, deliver);
        subscriptions.set(binding, () => element.off(type, deliver));
      },
      remove(binding) {
        subscriptions.get(binding)?.();
        subscriptions.delete(binding);
      },
    });
    replaying = false;
  }

  /**
   * Connects the drag adapter to the native table constructor.
   */
  function installTableDrag() {
    const { tableDragConstructor: tableDrag } = window.Drupal.tableInteraction;
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
    const { responsiveConstructor: TableResponsive } =
      window.Drupal.tableInteraction;
    collectionAlias(TableResponsive, 'Drupal.TableResponsive', 'table');
    collectionAlias(
      TableResponsive,
      'Drupal.TableResponsive',
      'link',
      false,
      (collection) => {
        const link = collection[0];
        if (link && !TableResponsive.pegged.has(link)) {
          TableResponsive.pegged.set(link, jquery.data(link, 'pegged'));
        }
        return collection;
      },
    );
    ['headers', 'revealedCells'].forEach((name) =>
      collectionAlias(TableResponsive, 'Drupal.TableResponsive', name, true),
    );
    adaptPeggedData(TableResponsive.pegged);
    const refreshes = new WeakSet();
    const migratedPegged = new WeakSet();
    const subscriptions = new Map();
    const bindings = new WeakMap();
    const windowEvents = jquery(window);
    const nativeRefresh = TableResponsive.refresh;
    const isLegacyResize = ({ handler, namespace }) =>
      !bindings.has(handler) &&
      typeof namespace === 'string' &&
      namespace.split('.').includes('tableresponsive');
    TableResponsive.refresh = () => {
      if (!jqueryHandlers(window, 'resize').some(isLegacyResize)) {
        const event = jquery.Event('resize', {
          target: window,
          currentTarget: window,
          namespace: 'tableresponsive',
          isTrigger: 3,
        });
        return nativeRefresh(toNativeEvent(event), () =>
          event.isImmediatePropagationStopped(),
        );
      }
      const event = jquery.Event('resize.tableresponsive');
      refreshes.add(event);
      windowEvents.trigger(event);
      return event.result;
    };

    const resize = jquery.event.special.resize ?? {};
    jquery.event.special.resize = resize;
    const { remove: previousRemove } = resize;
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

    let replaying = true;
    window.Drupal.eventBindings.observe({
      add(binding) {
        if (
          binding.target !== window ||
          binding.type !== 'resize' ||
          binding.namespace !== 'tableresponsive'
        ) {
          return;
        }
        const { link } = binding;
        if (link && !migratedPegged.has(link)) {
          migratedPegged.add(link);
          const stored = jquery.data(link, 'pegged');
          if (stored !== undefined) {
            TableResponsive.pegged.set(link, stored);
            jquery.removeData(link, 'pegged');
          }
        }
        const handler = (event) => {
          if (!event.isTrigger) {
            return deliverShared(binding, event);
          }
          if (!refreshes.has(event)) {
            warn('The synthetic resize.tableresponsive trigger');
          }
          return binding.listener(toNativeEvent(event));
        };
        // A replayed binding's native listener precedes the bridge subscription.
        if (replaying) {
          nativeResizeSlots.add(binding);
        }
        bindings.set(handler, binding);
        sharedHandlers.set(handler, binding);
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
    replaying = false;
  }

  /**
   * Shares the legacy pegged key with native responsive buttons.
   *
   * @param {WeakMap} pegged
   *   Stores the state by native button.
   */
  function adaptPeggedData(pegged) {
    const { data, removeData } = jquery.fn;
    jquery.fn.data = function (key, value) {
      if (key === 'pegged' && arguments.length === 1 && pegged.has(this[0])) {
        return pegged.get(this[0]);
      }
      if (key === undefined && pegged.has(this[0])) {
        const values = data.call(this);
        const current = pegged.get(this[0]);
        if (current === undefined) {
          delete values.pegged;
        } else {
          values.pegged = current;
        }
        return values;
      }
      const values = typeof key === 'string' ? { [key]: value } : key;
      if (values && values.pegged !== undefined) {
        this.each(function () {
          if (pegged.has(this)) {
            pegged.set(this, values.pegged);
          }
        });
      }
      return data.apply(this, arguments);
    };
    jquery.fn.removeData = function (key) {
      if (
        key === undefined ||
        (Array.isArray(key) ? key : String(key).split(/\s+/)).includes('pegged')
      ) {
        this.each(function () {
          if (pegged.has(this)) {
            pegged.set(this, undefined);
          }
        });
      }
      return removeData.apply(this, arguments);
    };
  }

  /**
   * Connects the Default Admin bulk actions adapter.
   */
  function installBulkActions() {
    const { bulkActionsConstructor: DefaultAdminBulkActions } =
      window.Drupal.tableInteraction;
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
    const select = window.Drupal.tableInteraction.tableSelect;
    const { checkboxContent, isSelectionChange } = select;
    const change = jquery.event.special.change ?? {};
    const previousHandle = change.handle;
    jquery.event.special.change = change;
    change.handle = function (event, ...args) {
      if (isSelectionChange(event.originalEvent)) {
        event.isTrigger = 3;
      }
      return (previousHandle ?? event.handleObj.handler).call(
        this,
        event,
        ...args,
      );
    };
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
    const { tableInteraction } = window.Drupal;
    tableInteraction.invokeEventCallback = invoke;
    tableInteraction.isSyntheticEvent = (event) =>
      Object.hasOwn(events.get(event) ?? event ?? {}, 'isTrigger');
    // jQuery 4 retains its original synthetic event while stimulating native
    // checkbox activation and focus. Deliver the table operation only when
    // that synthetic event reaches its jQuery handler, with its modifiers intact.
    tableInteraction.allowNativeEvent = (event) => {
      const { type, target } = event;
      const leveragesNative =
        type === 'focus' ||
        type === 'blur' ||
        (type === 'click' &&
          target.matches?.('input:is([type="checkbox"], [type="radio"])'));
      // Other triggers, such as button clicks, may also run a native default
      // action after jQuery has already delivered the table handler.
      return (
        jquery.event.triggered !== type &&
        (!leveragesNative || !jquery._data(target, type))
      );
    };
  }

  installSharedDispatch();
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
