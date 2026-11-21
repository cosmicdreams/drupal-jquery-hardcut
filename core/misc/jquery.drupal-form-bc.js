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
  const legacyEvents = new WeakMap();
  const nativeDeliveries = new WeakMap();
  const bindingChecks = new WeakMap();
  // Maps each connected instance to the function that removes its adapter.
  const adapters = new Map();

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

  const fragmentEvent = 'formFragmentLinkClickOrHashChange';
  const valueCopyEvent = 'value:copy';
  const drupalCustomEvents = new Set([
    'formUpdated',
    'summaryUpdated',
    fragmentEvent,
    valueCopyEvent,
  ]);
  const tabbingMigration = 'Use native context members and element lists.';
  const tabbingEvents = [
    'drupalTabbingConstrained',
    'drupalTabbingContextActivated',
    'drupalTabbingContextDeactivated',
    'drupalTabbingContextReleased',
  ];

  /**
   * Checks whether a registry binding belongs to the form interaction adapter.
   */
  function isFormBinding(binding) {
    return Boolean(
      binding &&
      (binding.formInteraction ||
        binding.detailsAria ||
        binding.tabbingFocusTrap ||
        binding.navTabs),
    );
  }

  /**
   * Preserves cancellation and return values in the legacy queue.
   */
  function deliverFormInteraction(listener, native, event, matched) {
    const stop = native.stopImmediatePropagation;
    const descriptor = Object.getOwnPropertyDescriptor(
      native,
      'stopImmediatePropagation',
    );
    native.stopImmediatePropagation = function () {
      stop.call(this);
      event.isImmediatePropagationStopped = () => true;
      event.isPropagationStopped = () => true;
    };
    const previous = legacyEvents.get(native);
    legacyEvents.set(native, event);
    try {
      const result = listener(native, matched);
      if (native.defaultPrevented) {
        event.preventDefault();
      }
      if (native.cancelBubble && !event.isPropagationStopped()) {
        event.stopPropagation();
      }
      return result;
    } finally {
      if (previous) {
        legacyEvents.set(native, previous);
      } else {
        legacyEvents.delete(native);
      }
      if (descriptor) {
        Object.defineProperty(native, 'stopImmediatePropagation', descriptor);
      } else {
        delete native.stopImmediatePropagation;
      }
    }
  }

  /**
   * Lets each connected instance decide whether it delivers a native event.
   */
  function trackBinding(binding, handles) {
    let checks = bindingChecks.get(binding);
    if (!checks) {
      checks = new Set();
      bindingChecks.set(binding, checks);
      binding.handlesNativeEvent = (event) =>
        Array.from(checks).some((check) => check(event));
    }
    checks.add(handles);
  }

  /**
   * Removes one instance's native delivery decision for a binding.
   */
  function untrackBinding(binding, handles) {
    const checks = bindingChecks.get(binding);
    if (checks?.delete(handles) && !checks.size) {
      bindingChecks.delete(binding);
      delete binding.handlesNativeEvent;
    }
  }

  /**
   * Records one delivery of a native event, whichever instance dispatches it.
   */
  function claimNativeDelivery(event, binding) {
    let delivered = nativeDeliveries.get(event);
    if (!delivered) {
      delivered = new WeakSet();
      nativeDeliveries.set(event, delivered);
    }
    if (delivered.has(binding)) {
      return false;
    }
    delivered.add(binding);
    return true;
  }

  /**
   * Installs lazy legacy bindings for one instance and keeps owned order.
   *
   * @param {Function} jquery
   *   The connected instance.
   * @return {function(): void}
   *   Removes this instance's bindings and hooks.
   */
  function install(jquery) {
    const { Drupal } = window;
    const entries = new Map();
    const byTarget = new WeakMap();
    const handlers = new Map();
    const observed = new Map();
    const triggerFrames = [];
    // Bindings attached for synthetic triggers, released by the outermost one.
    const tempAttached = new Set();
    let internalDepth = 0;
    // jQuery exposes its internal returnTrue only on a prevented event.
    const probe = jquery.Event('drupal:form-probe');
    probe.preventDefault();
    const returnTrue = probe.isDefaultPrevented;

    function getBindType(type, selector) {
      const special = jquery.event.special[type];
      return (selector ? special?.delegateType : special?.bindType) || type;
    }

    // The leverageNative controller and its trigger stub are jQuery's own.
    function isInternalHandler(handleObj) {
      return handleObj.namespace === false || handleObj.handler === returnTrue;
    }

    function hasExternalHandler(target, bindType) {
      const list = jquery._data(target, 'events')?.[bindType];
      return Boolean(
        list?.some(
          (handleObj) =>
            !handlers.has(handleObj.guid) && !isInternalHandler(handleObj),
        ),
      );
    }

    function isSyntheticDefaultAction(event) {
      if (Drupal.eventBindings.isForeignDefaultAction?.(event)) {
        return true;
      }
      const frame = triggerFrames.at(-1);
      if (
        !frame ||
        frame.type !== event.type ||
        frame.target !== event.target
      ) {
        return false;
      }
      let synthetic = jquery.event.triggered === event.type;
      if (!synthetic && ['click', 'focus', 'blur'].includes(event.type)) {
        const phase = jquery._data(event.target, event.type);
        synthetic =
          Array.isArray(phase) ||
          (Boolean(phase) && Object.hasOwn(phase, 'value'));
      }
      if (synthetic) {
        frame.defaultEvent = frame.defaultEvent || event;
        return frame.defaultEvent === event;
      }
      return false;
    }

    function observeRemoval(type) {
      if (observed.has(type)) {
        return;
      }
      const specials = jquery.event.special;
      const existing = specials[type];
      const special = existing || (specials[type] = {});
      const hadRemove = Object.hasOwn(special, 'remove');
      const remove = special.remove;
      const hook = function (handle) {
        try {
          remove?.call(this, handle);
        } finally {
          const entry = handlers.get(handle.guid);
          if (entry?.binding.target === this) {
            handlers.delete(handle.guid);
            entry.attached = false;
            entry.slot = undefined;
            entry.handler = undefined;
            entry.binding.stop();
          }
        }
      };
      special.remove = hook;
      observed.set(type, () => {
        if (special.remove !== hook) {
          return;
        }
        if (hadRemove) {
          special.remove = remove;
        } else {
          delete special.remove;
        }
        if (
          !existing &&
          specials[type] === special &&
          !Reflect.ownKeys(special).length
        ) {
          delete specials[type];
        }
      });
    }

    function attachEntry(entry) {
      if (entry.attached) {
        return;
      }
      const { binding, name, deliver, bindType } = entry;
      const { target, selector } = binding;
      // A new jQuery handler list runs after every native listener already on
      // the target, so core keeps its native position for real events.
      entry.nativeOrder = !hasExternalHandler(target, bindType);
      internalDepth += 1;
      try {
        const slot = Drupal.eventBindings.claimLegacyEvent?.(
          jquery,
          binding,
          deliver,
        );
        const handler = slot?.handler ?? deliver;
        if (!slot) {
          jquery(target).on(name, selector, handler);
        }
        entry.slot = slot;
        entry.handler = handler;
        entry.attached = true;
        handlers.set(handler.guid, entry);
      } finally {
        internalDepth -= 1;
      }
    }

    // Removes jQuery's own leftovers so the element has no jQuery listener.
    function releaseInternalHandlers(target, bindType) {
      const list = jquery._data(target, 'events')?.[bindType];
      if (list?.length && list.every(isInternalHandler)) {
        internalDepth += 1;
        try {
          jquery.event.remove(target, bindType);
        } finally {
          internalDepth -= 1;
        }
      }
    }

    function detachEntry(entry) {
      if (!entry.attached) {
        return;
      }
      const { binding, name, handler, slot, bindType } = entry;
      const { target, selector } = binding;
      entry.attached = false;
      entry.slot = undefined;
      entry.handler = undefined;
      handlers.delete(handler.guid);
      internalDepth += 1;
      try {
        if (slot) {
          slot.remove(true);
        } else {
          jquery(target).off(name, selector, handler);
        }
      } finally {
        internalDepth -= 1;
      }
      releaseInternalHandlers(target, bindType);
    }

    function syncTargetType(target, bindType) {
      if (triggerFrames.length > 0 || hasExternalHandler(target, bindType)) {
        return;
      }
      byTarget.get(target)?.forEach((entry) => {
        if (entry.bindType === bindType && entry.attached) {
          detachEntry(entry);
        }
      });
    }

    /**
     * Moves a handler ahead of handlers that predate the adapters.
     *
     * Synthetic triggers then reach core before contrib handlers that were
     * bound after core's native listener, as with the pinned registration.
     */
    function placeBeforeEarlierHandlers(entry, floor) {
      const list = jquery._data(entry.binding.target, 'events')?.[
        entry.bindType
      ];
      const index =
        list?.findIndex(({ guid }) => guid === entry.handler.guid) ?? -1;
      if (index < 0) {
        return;
      }
      const start = entry.binding.selector ? 0 : list.delegateCount;
      for (let position = start; position < index; position += 1) {
        const handleObj = list[position];
        if (
          !handlers.has(handleObj.guid) &&
          !isInternalHandler(handleObj) &&
          handleObj.guid < floor
        ) {
          list.splice(position, 0, ...list.splice(index, 1));
          return;
        }
      }
    }

    function materialize(target, types, selector) {
      const targetEntries = byTarget.get(target);
      if (!targetEntries?.size) {
        return;
      }
      const wanted = new Set();
      if (typeof types === 'string' && types.trim()) {
        types
          .trim()
          .split(/\s+/)
          .forEach((token) => {
            const raw = token.split('.')[0];
            if (raw) {
              wanted.add(raw);
              wanted.add(getBindType(raw, selector));
              const special = jquery.event.special[raw];
              if (special?.bindType) {
                wanted.add(special.bindType);
              }
              if (special?.delegateType) {
                wanted.add(special.delegateType);
              }
            }
          });
      }
      targetEntries.forEach((entry) => {
        if (
          !entry.attached &&
          (!wanted.size ||
            wanted.has(entry.bindType) ||
            wanted.has(entry.binding.type))
        ) {
          attachEntry(entry);
        }
      });
    }

    const originalAdd = jquery.event.add;
    const add = function (elem, types, handler, data, selector) {
      if (internalDepth === 0 && elem) {
        materialize(elem, types, selector);
      }
      return originalAdd.apply(this, arguments);
    };
    jquery.event.add = add;

    let removeDepth = 0;
    const originalRemove = jquery.event.remove;
    const remove = function (elem, types, handler, selector) {
      if (internalDepth === 0 && removeDepth === 0 && elem) {
        materialize(elem, types, selector);
      }
      removeDepth += 1;
      try {
        return originalRemove.apply(this, arguments);
      } finally {
        removeDepth -= 1;
        if (internalDepth === 0 && removeDepth === 0 && elem) {
          const targetEntries = byTarget.get(elem);
          if (targetEntries?.size) {
            const bindTypes = new Set(
              Array.from(targetEntries, (entry) => entry.bindType),
            );
            bindTypes.forEach((bindType) => syncTargetType(elem, bindType));
          }
        }
      }
    };
    jquery.event.remove = remove;

    const originalTrigger = jquery.event.trigger;
    const trigger = function (event, data, elem, onlyHandlers) {
      const rawType = (typeof event === 'string' ? event : event.type).split(
        '.',
      )[0];
      const target = elem || document;
      if (internalDepth === 0 && rawType) {
        const special = jquery.event.special[rawType];
        const directTypes = new Set([
          rawType,
          special?.bindType || rawType,
          special?.delegateType || rawType,
        ]);
        const bubbleTypes = new Set([
          rawType,
          special?.delegateType || rawType,
        ]);
        const nodes = [target];
        if (!onlyHandlers) {
          let current = target.parentNode;
          while (current) {
            nodes.push(current);
            current = current.parentNode;
          }
          const view =
            target !== window
              ? target.defaultView || target.ownerDocument?.defaultView
              : null;
          if (view) {
            nodes.push(view);
          }
        }
        nodes.forEach((node, index) => {
          const typeSet = index === 0 ? directTypes : bubbleTypes;
          byTarget.get(node)?.forEach((entry) => {
            if (
              !entry.attached &&
              (typeSet.has(entry.bindType) || typeSet.has(entry.binding.type))
            ) {
              attachEntry(entry);
              tempAttached.add(entry);
            }
          });
        });
      }
      triggerFrames.push({ type: rawType, target });
      try {
        return originalTrigger.apply(this, arguments);
      } finally {
        triggerFrames.pop();
        if (!triggerFrames.length) {
          const attached = Array.from(tempAttached);
          tempAttached.clear();
          attached.forEach((entry) => {
            if (
              entry.attached &&
              !hasExternalHandler(entry.binding.target, entry.bindType)
            ) {
              detachEntry(entry);
            }
          });
        }
      }
    };
    jquery.event.trigger = trigger;

    let replaying = true;
    const replayed = [];
    const stopObserving = Drupal.eventBindings.observe({
      add(binding) {
        if (!isFormBinding(binding) || entries.has(binding)) {
          return;
        }
        const { target, selector, type, namespace, listener, formInteraction } =
          binding;
        const bindType = getBindType(type, selector);
        observeRemoval(type);
        if (bindType !== type) {
          observeRemoval(bindType);
        }
        const name = namespace ? `${type}.${namespace}` : type;
        const entry = {
          binding,
          bindType,
          name,
          attached: false,
          nativeOrder: false,
          slot: undefined,
          handler: undefined,
        };
        entry.deliver = function (event, fragmentTarget) {
          if (!event.isTrigger) {
            if (
              entry.nativeOrder ||
              Drupal.eventBindings.isForeignDefaultAction?.(
                event.originalEvent,
              ) ||
              !hasExternalHandler(target, bindType) ||
              (event.originalEvent &&
                !claimNativeDelivery(event.originalEvent, binding))
            ) {
              return undefined;
            }
          } else if (drupalCustomEvents.has(type)) {
            warn(
              `The jQuery synthetic trigger for ${type}`,
              'Use the same native event name and its documented payload.',
            );
          }
          if (formInteraction) {
            let native = event.originalEvent;
            if (event.isTrigger || !(native instanceof Event)) {
              native = new CustomEvent(type, {
                cancelable: true,
                detail:
                  type === fragmentEvent
                    ? (jquery(fragmentTarget)[0] ?? null)
                    : type === valueCopyEvent
                      ? fragmentTarget
                      : undefined,
              });
              Object.defineProperties(native, {
                target: { value: event.target },
                currentTarget: { value: event.currentTarget },
                key: { value: event.key },
                altKey: {
                  value: Boolean(event.altKey ?? event.originalEvent?.altKey),
                },
                ctrlKey: {
                  value: Boolean(event.ctrlKey ?? event.originalEvent?.ctrlKey),
                },
                metaKey: {
                  value: Boolean(event.metaKey ?? event.originalEvent?.metaKey),
                },
                shiftKey: {
                  value: Boolean(
                    event.shiftKey ?? event.originalEvent?.shiftKey,
                  ),
                },
                keyCode: { value: event.keyCode },
                which: { value: event.which },
                button: {
                  value: event.button ?? event.originalEvent?.button,
                },
                buttons: {
                  value: event.buttons ?? event.originalEvent?.buttons,
                },
              });
              if (event.isDefaultPrevented()) {
                native.preventDefault();
              }
            }
            return deliverFormInteraction(listener, native, event, this);
          }
          if (type === 'click') {
            return listener(this);
          }
          const native =
            !event.isTrigger && event.originalEvent instanceof Event
              ? event.originalEvent
              : new KeyboardEvent(type, {
                  cancelable: true,
                  key: event.key,
                  shiftKey: event.shiftKey,
                });
          const result = listener(native);
          if (native.defaultPrevented) {
            event.preventDefault();
          }
          return result;
        };
        entry.handles = (event) => {
          if (isSyntheticDefaultAction(event)) {
            return true;
          }
          if (
            !entry.attached ||
            entry.nativeOrder ||
            !hasExternalHandler(target, bindType)
          ) {
            return false;
          }
          return bindType === event.type || event.isTrusted;
        };

        entries.set(binding, entry);
        let targetEntries = byTarget.get(target);
        if (!targetEntries) {
          targetEntries = new Set();
          byTarget.set(target, targetEntries);
        }
        targetEntries.add(entry);
        trackBinding(binding, entry.handles);

        if (replaying) {
          // Claim any slot reserved in registration order by
          // jquery.drupal-bc.js; settle the replayed entries below.
          internalDepth += 1;
          try {
            const slot = Drupal.eventBindings.claimLegacyEvent?.(
              jquery,
              binding,
              entry.deliver,
            );
            if (slot) {
              entry.slot = slot;
              entry.handler = slot.handler;
              entry.attached = true;
              handlers.set(slot.handler.guid, entry);
            }
          } finally {
            internalDepth -= 1;
          }
          replayed.push(entry);
        } else if (hasExternalHandler(target, bindType)) {
          attachEntry(entry);
        }
      },
      remove(binding) {
        const entry = entries.get(binding);
        if (!entry) {
          return;
        }
        entries.delete(binding);
        byTarget.get(binding.target)?.delete(entry);
        tempAttached.delete(entry);
        detachEntry(entry);
        untrackBinding(binding, entry.handles);
      },
    });
    replaying = false;

    // Handlers created before the reserved slots predate the adapters.
    const floor = replayed.reduce(
      (lowest, entry) => Math.min(lowest, entry.slot?.handler.guid ?? lowest),
      Infinity,
    );
    replayed.forEach((entry) => {
      const { target } = entry.binding;
      if (!hasExternalHandler(target, entry.bindType)) {
        // Unmixed targets stay on pure native DOM delivery.
        detachEntry(entry);
        return;
      }
      // The registration order of handlers bound before the adapters loaded is
      // unknown. Core's native listener keeps its place for real events.
      attachEntry(entry);
      entry.nativeOrder = true;
      placeBeforeEarlierHandlers(entry, floor);
    });

    const stopPublications = installPublications(jquery);

    return () => {
      stopObserving();
      stopPublications();
      if (jquery.event.add === add) {
        jquery.event.add = originalAdd;
      }
      if (jquery.event.remove === remove) {
        jquery.event.remove = originalRemove;
      }
      if (jquery.event.trigger === trigger) {
        jquery.event.trigger = originalTrigger;
      }
      observed.forEach((restore) => restore());
      observed.clear();
    };
  }

  /**
   * Supplies the positional arguments of existing form and tabbing events.
   *
   * @param {Function} jquery
   *   The connected instance.
   * @return {function(): void}
   *   Stops supplying the arguments.
   */
  function installPublications(jquery) {
    const publications = new WeakMap();
    const legacyTypes = [...tabbingEvents, fragmentEvent, valueCopyEvent];
    const stop = window.Drupal.eventBindings.observePublications((event) => {
      if (legacyTypes.includes(event.type)) {
        publications.set(event, [
          event.type === fragmentEvent ? jquery(event.detail) : event.detail,
        ]);
        return () => publications.delete(event);
      }
      return undefined;
    });
    const restores = legacyTypes.map((type) => {
      const specials = jquery.event.special;
      const existing = specials[type];
      const special = existing || (specials[type] = {});
      const hadHandle = Object.hasOwn(special, 'handle');
      const previous = special.handle;
      const handle = function (event, ...args) {
        const positional =
          !event.isTrigger && publications.get(event.originalEvent);
        return (previous || event.handleObj.handler).call(
          this,
          event,
          ...(positional || args),
        );
      };
      special.handle = handle;
      return () => {
        if (special.handle !== handle) {
          return;
        }
        if (hadHandle) {
          special.handle = previous;
        } else {
          delete special.handle;
        }
        if (
          !existing &&
          specials[type] === special &&
          !Reflect.ownKeys(special).length
        ) {
          delete specials[type];
        }
      };
    });
    return () => {
      stop();
      restores.forEach((restore) => restore());
    };
  }

  /**
   * Gives explicitly connected instances the same form compatibility.
   */
  function connectInstances() {
    const registry = window.Drupal.eventBindings;
    // Report the bindings an installed adapter delivers so
    // jquery.drupal-bc.js reserves no second slot for them.
    const previousOwner = registry.ownsLegacyEvents;
    registry.ownsLegacyEvents = (instance, binding) =>
      (adapters.has(instance) && isFormBinding(binding)) ||
      Boolean(previousOwner?.(instance, binding));
    adapters.set(jquery, install(jquery));

    const connect = registry.connectLegacy;
    if (typeof connect !== 'function') {
      return;
    }
    const disconnects = new WeakMap();
    registry.connectLegacy = function (instance) {
      // The bridge reserves slots in registry order before the adapter
      // claims them.
      const disconnect = connect.call(this, instance);
      if (typeof instance !== 'function' || instance === jquery) {
        return disconnect;
      }
      let stop = disconnects.get(instance);
      if (stop?.disconnect !== disconnect) {
        if (!adapters.has(instance)) {
          adapters.set(instance, install(instance));
        }
        stop = () => {
          if (disconnects.get(instance) === stop) {
            disconnects.delete(instance);
            adapters.get(instance)?.();
            adapters.delete(instance);
            disconnect();
          }
        };
        stop.disconnect = disconnect;
        disconnects.set(instance, stop);
      }
      return stop;
    };
  }

  /**
   * Installs the legacy machine-name callback argument and assignment hook.
   */
  function installMachineNameMethods() {
    const behavior = window.Drupal.behaviors.machineName;
    const original = behavior.showMachineNameNative;
    const cache = new WeakMap();
    const members = ['source', 'target', 'suffix', 'wrapper', 'preview'];
    const contract = 'Drupal.behaviors.machineName.showMachineName';
    const migration =
      'Use showMachineNameNative(machine, data) with native elements.';
    const callDefault = function (machine, data) {
      warn(contract, migration);
      const native = { options: data.options };
      members.forEach((name) => {
        [native[name]] = jquery(data[`$${name}`]);
      });
      return original.call(this, machine, native);
    };
    let method = behavior.showMachineName;
    Object.defineProperty(behavior, 'showMachineName', {
      configurable: true,
      get() {
        return method || callDefault;
      },
      set(callback) {
        method = callback;
        if (!callback || callback === callDefault) {
          behavior.showMachineNameNative = original;
          return;
        }
        warn(contract, migration);
        behavior.showMachineNameNative = function (machine, data) {
          let legacy = cache.get(data);
          if (!legacy) {
            legacy = { options: data.options };
            members.forEach((name) => {
              legacy[`$${name}`] = jquery(data[name]);
            });
            cache.set(data, legacy);
          }
          return callback.call(this, machine, legacy);
        };
      },
    });
    if (method) {
      behavior.showMachineName = method;
    }
  }

  /**
   * Adapts the legacy copy-field handlers and their positional value.
   */
  function installCopyFieldMethods() {
    const behavior = window.Drupal.behaviors.copyFieldValue;
    for (const name of ['valueTargetCopyHandler', 'valueSourceBlurHandler']) {
      const nativeName = `${name}Native`;
      const native = behavior[nativeName];
      const targetHandler = name === 'valueTargetCopyHandler';
      const contract = `Drupal.behaviors.copyFieldValue.${name}`;
      const migration = `Use ${nativeName} with a native event.`;
      const callDefault = function (event, value) {
        warn(contract, migration);
        return targetHandler
          ? native.call(this, { target: event.target, detail: value })
          : native.call(this, event.originalEvent || event);
      };
      let override = behavior[name];
      Object.defineProperty(behavior, name, {
        configurable: true,
        get() {
          return override || callDefault;
        },
        set(callback) {
          override = callback === callDefault ? undefined : callback;
          if (!override) {
            behavior[nativeName] = native;
            return;
          }
          warn(contract, migration);
          behavior[nativeName] = function (event) {
            const legacy = legacyEvents.get(event) || jquery.Event(event);
            const result = targetHandler
              ? callback.call(this, legacy, event.detail)
              : callback.call(this, legacy);
            if (result === false) {
              legacy.preventDefault();
              legacy.stopPropagation();
            }
            return result;
          };
        },
      });
      if (override) {
        behavior[name] = override;
      }
    }
  }

  /**
   * Adapts legacy dependent translation option overrides.
   */
  function installTranslationOptions() {
    const behavior = window.Drupal.behaviors.contentTranslationDependentOptions;
    const native = behavior.checkNative;
    const contract =
      'Drupal.behaviors.contentTranslationDependentOptions.check';
    const migration = 'Use checkNative with native input elements.';
    const callDefault = function (fields, columns, changed) {
      warn(contract, migration);
      return native.call(
        this,
        jquery(fields).toArray(),
        columns,
        jquery(changed)[0],
      );
    };
    let override = behavior.check;
    Object.defineProperty(behavior, 'check', {
      configurable: true,
      get() {
        return override || callDefault;
      },
      set(callback) {
        override = callback === callDefault ? undefined : callback;
        behavior.checkNative = override
          ? function (fields, columns, changed) {
              warn(contract, migration);
              return callback.call(
                this,
                jquery(fields),
                columns,
                changed && jquery(changed),
              );
            }
          : native;
      },
    });
    if (override) {
      behavior.check = override;
    }
  }

  /**
   * Installs the legacy summary plugins on an explicitly attached instance.
   */
  function installSummaryMethods() {
    const { form } = window.Drupal;
    const summaryMigration =
      'Use the corresponding native Drupal form summary function.';
    jquery.fn.drupalGetSummary = function () {
      warn('jQuery.fn.drupalGetSummary', summaryMigration);
      return form.getSummary(this[0]);
    };
    jquery.fn.drupalSetSummary = function (summary) {
      warn('jQuery.fn.drupalSetSummary', summaryMigration);
      this.off('formUpdated.summary');
      form.setSummary(this.toArray(), summary);
      return this;
    };
  }

  /**
   * Preserves a legacy collection accessor and its identity until assignment.
   */
  function collectionAccessor(
    prototype,
    owner,
    legacyName,
    nativeName,
    migration,
    list = false,
  ) {
    const cached = new WeakMap();
    const contract = `${owner}#${legacyName}`;
    Object.defineProperty(prototype, legacyName, {
      configurable: true,
      get() {
        warn(contract, migration);
        const value = this[nativeName];
        let entry = cached.get(this);
        if (!entry || entry.value !== value) {
          entry = { value, collection: jquery(value) };
          cached.set(this, entry);
        }
        return entry.collection;
      },
      set(input) {
        warn(contract, migration);
        const collection = input instanceof jquery ? input : jquery(input);
        const value = list ? collection.toArray() : collection[0];
        this[nativeName] = value;
        cached.set(this, { value, collection });
      },
    });
  }

  /**
   * Installs legacy DropButton collections and event overrides.
   */
  function installDropButton() {
    const { Drupal } = window;
    const NativeDropButton = Drupal.DropButton;
    const prototype = NativeDropButton.prototype;
    const migration = 'Use the native DropButton elements and event methods.';
    for (const [legacyName, nativeName, list] of [
      ['$dropbutton', 'wrapperElement', false],
      ['$list', 'listElements', true],
      ['$actions', 'actionElements', true],
    ]) {
      collectionAccessor(
        prototype,
        'Drupal.DropButton',
        legacyName,
        nativeName,
        migration,
        list,
      );
    }

    ['focusIn', 'focusOut', 'hoverIn', 'hoverOut'].forEach((name) => {
      const nativeName = `${name}Native`;
      const native = prototype[nativeName];
      const contract = `Drupal.DropButton#${name}`;
      const callDefault = function (event) {
        warn(contract, migration);
        return native.call(this, event?.originalEvent || event);
      };
      const existing = Object.hasOwn(prototype, name)
        ? prototype[name]
        : undefined;
      Object.defineProperty(prototype, name, {
        configurable: true,
        get() {
          return this[nativeName]?.legacyCallback || callDefault;
        },
        set(callback) {
          if (!callback || callback === callDefault) {
            if (this === prototype) {
              prototype[nativeName] = native;
            } else {
              delete this[nativeName];
            }
            return;
          }
          warn(contract, migration);
          const adapted = function (event) {
            const legacy =
              legacyEvents.get(event) ||
              (event instanceof jquery.Event ? event : jquery.Event(event));
            const result = callback.call(this, legacy);
            if (result === false) {
              legacy.preventDefault();
              legacy.stopPropagation();
            }
            return result;
          };
          adapted.legacyCallback = callback;
          this[nativeName] = adapted;
        },
      });
      if (existing) {
        prototype[name] = existing;
      }
      NativeDropButton.dropbuttons.forEach((instance) => {
        if (Object.hasOwn(instance, name)) {
          const own = instance[name];
          delete instance[name];
          instance[name] = own;
        }
      });
    });

    Drupal.DropButton = function (element, options) {
      if (element?.nodeType !== Node.ELEMENT_NODE) {
        warn('The jQuery input to Drupal.DropButton', migration);
        [element] = jquery(element);
      }
      if (new.target) {
        return Reflect.construct(
          NativeDropButton,
          [element, options],
          new.target,
        );
      }
      return NativeDropButton.call(this, element, options);
    };
    Object.setPrototypeOf(Drupal.DropButton, NativeDropButton);
    Drupal.DropButton.prototype = prototype;
  }

  /**
   * Keeps legacy tab collections, constructor inputs and theme overrides.
   */
  function installVerticalTabs() {
    const { Drupal } = window;
    const NativeTab =
      Drupal.verticalTabs.nativeConstructor || Drupal.verticalTab;
    const preBridgeOverride =
      Drupal.verticalTab !== NativeTab ? Drupal.verticalTab : undefined;
    const migration =
      'Use Drupal.verticalTabs.get(pane) and native tab elements.';
    const markup = ['item', 'link', 'title', 'summary'];
    [...markup, 'details'].forEach((name) => {
      collectionAccessor(
        NativeTab.prototype,
        'Drupal.verticalTab',
        name,
        `${name}Element`,
        migration,
      );
    });

    function defaultConstructor(settings) {
      if (!settings.detailsElement) {
        const { details, ...remaining } = settings;
        warn('The jQuery input to Drupal.verticalTab', migration);
        settings = { ...remaining, detailsElement: jquery(details)[0] };
      } else if (
        Object.getOwnPropertyDescriptor(settings, 'details')?.enumerable
      ) {
        // An override passed a copy with a replaced details collection.
        const { details, ...remaining } = settings;
        settings = { ...remaining, detailsElement: jquery(details)[0] };
      }
      if (new.target) {
        return Reflect.construct(NativeTab, [settings], new.target);
      }
      return NativeTab.call(this, settings);
    }
    defaultConstructor.prototype = NativeTab.prototype;

    function wrapConstructorOverride(Constructor) {
      if (Constructor.nativeVerticalTab) {
        return Constructor;
      }
      function AdaptedTab(settings) {
        if (settings?.detailsElement && !settings.details) {
          warn('Drupal.verticalTab', migration);
          let details = jquery(settings.detailsElement);
          settings = { ...settings };
          // Keep the collection out of Object.assign() and object spread, and
          // let an override that replaces it also replace the native pane.
          Object.defineProperty(settings, 'details', {
            configurable: true,
            get() {
              return details;
            },
            set(value) {
              details = value instanceof jquery ? value : jquery(value);
              settings.detailsElement = details[0];
            },
          });
        }
        if (new.target) {
          return Reflect.construct(Constructor, [settings], new.target);
        }
        return Constructor.call(this, settings);
      }
      AdaptedTab.prototype = Constructor.prototype;
      return AdaptedTab;
    }

    let activeConstructor = preBridgeOverride
      ? wrapConstructorOverride(preBridgeOverride)
      : defaultConstructor;
    Object.defineProperty(Drupal, 'verticalTab', {
      configurable: true,
      get() {
        return activeConstructor;
      },
      set(value) {
        if (!value || value === defaultConstructor || value === NativeTab) {
          activeConstructor = defaultConstructor;
          return;
        }
        activeConstructor = wrapConstructorOverride(value);
      },
    });

    // Copy each tab into jQuery data on first read, so a removed value stays
    // removed. A pane read before its tab exists is copied later.
    const migratedPanes = new WeakSet();
    function ensureTabData(pane) {
      if (!pane || typeof pane !== 'object' || migratedPanes.has(pane)) {
        return;
      }
      const tab = Drupal.verticalTabs.get(pane);
      if (!tab) {
        return;
      }
      migratedPanes.add(pane);
      if (originalData(pane, 'verticalTab') === undefined) {
        originalData(pane, 'verticalTab', tab);
      }
    }

    const originalData = jquery.data;
    document.querySelectorAll('.vertical-tabs__pane').forEach((pane) => {
      ensureTabData(pane);
    });

    const originalFnData = jquery.fn.data;
    jquery.fn.data = function (key) {
      if (
        (key === 'verticalTab' || key === undefined) &&
        arguments.length <= 1 &&
        this[0]
      ) {
        ensureTabData(this[0]);
      }
      return originalFnData.apply(this, arguments);
    };

    jquery.data = function (elem, name) {
      if (
        (name === 'verticalTab' || name === undefined) &&
        arguments.length <= 2 &&
        elem
      ) {
        ensureTabData(elem);
      }
      return originalData.apply(this, arguments);
    };

    const nativeMarkup = Drupal.verticalTabs.createMarkup;
    const themeMigration =
      'Use Drupal.theme.verticalTabElements with native elements.';
    const legacyTheme = (settings) => {
      warn('Drupal.theme.verticalTab', themeMigration);
      const { details, ...remaining } = settings;
      const elements = nativeMarkup({
        ...remaining,
        detailsElement: jquery(details)[0],
      });
      return Object.fromEntries(
        markup.map((name) => [name, jquery(elements[`${name}Element`])]),
      );
    };
    let override = Drupal.theme.verticalTab;
    Object.defineProperty(Drupal.theme, 'verticalTab', {
      configurable: true,
      get() {
        return override || legacyTheme;
      },
      set(callback) {
        override = callback === legacyTheme ? undefined : callback;
      },
    });
    Drupal.verticalTabs.createMarkup = (settings) => {
      if (!override) {
        return nativeMarkup(settings);
      }
      warn('Drupal.theme.verticalTab', themeMigration);
      const { detailsElement, ...remaining } = settings;
      const result = override({
        ...remaining,
        details: jquery(detailsElement),
      });
      return Object.fromEntries(
        markup.map((name) => [`${name}Element`, jquery(result[name])[0]]),
      );
    };
  }

  /**
   * Supplies the collection accessors used by legacy details overrides.
   */
  function installDetailsMembers() {
    const prototype = window.Drupal.DetailsSummarizedContent.prototype;
    const native = prototype.onSummaryUpdatedNative;
    const contract = 'Drupal.DetailsSummarizedContent#onSummaryUpdated';
    const migration = 'Use onSummaryUpdatedNative() with a native event.';
    const callDefault = function (event) {
      warn(contract, migration);
      return native.call(this, event?.originalEvent || event);
    };
    Object.defineProperty(prototype, 'onSummaryUpdated', {
      configurable: true,
      get() {
        return this.onSummaryUpdatedNative?.legacyCallback || callDefault;
      },
      set(callback) {
        if (!callback || callback === callDefault) {
          if (this === prototype) {
            prototype.onSummaryUpdatedNative = native;
          } else {
            delete this.onSummaryUpdatedNative;
          }
          return;
        }
        warn(contract, migration);
        // The pinned listener received the jQuery event, including during the
        // initial summary rendering.
        const adapted = function (event) {
          const legacy =
            legacyEvents.get(event) ||
            (event instanceof jquery.Event ? event : jquery.Event(event));
          const result = callback.call(this, legacy);
          if (result === false) {
            legacy.preventDefault();
            legacy.stopPropagation();
          }
          return result;
        };
        adapted.legacyCallback = callback;
        this.onSummaryUpdatedNative = adapted;
      },
    });
    for (const [legacyName, nativeName] of [
      ['$node', 'node'],
      ['$detailsSummarizedContentWrapper', 'summaryWrapper'],
    ]) {
      collectionAccessor(
        prototype,
        'Drupal.DetailsSummarizedContent',
        legacyName,
        nativeName,
        `Use the native ${nativeName} element.`,
      );
    }
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
      const legacyName = `$${name}`;
      collectionAccessor(
        manager.contextPrototype,
        'TabbingContext',
        legacyName,
        name,
        tabbingMigration,
        true,
      );
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
          method = callback === defaultMethod ? undefined : callback;
          if (!method) {
            manager[nativeName] = native;
            return;
          }
          warn(contract, tabbingMigration);
          manager[nativeName] = function (element, level) {
            return method.call(this, jquery(element), level);
          };
        },
      });
      if (method) {
        manager[legacyName] = method;
      }
    });
  }

  if (window.Drupal?.eventBindings) {
    connectInstances();
  } else {
    document.addEventListener('drupal:event-bindings-ready', connectInstances, {
      once: true,
    });
  }
  if (window.Drupal?.behaviors?.machineName) {
    installMachineNameMethods();
  } else {
    document.addEventListener(
      'drupal:machine-name-ready',
      installMachineNameMethods,
      { once: true },
    );
  }
  if (window.Drupal?.form) {
    installSummaryMethods();
  } else {
    document.addEventListener('drupal:form-ready', installSummaryMethods, {
      once: true,
    });
  }
  if (window.Drupal?.behaviors?.contentTranslationDependentOptions) {
    installTranslationOptions();
  } else {
    document.addEventListener(
      'drupal:translation-options-ready',
      installTranslationOptions,
      { once: true },
    );
  }
  if (window.Drupal?.behaviors?.copyFieldValue) {
    installCopyFieldMethods();
  } else {
    document.addEventListener(
      'drupal:copy-field-value-ready',
      installCopyFieldMethods,
      { once: true },
    );
  }
  if (window.Drupal?.DropButton) {
    installDropButton();
  } else {
    document.addEventListener('drupal:dropbutton-ready', installDropButton, {
      once: true,
    });
  }
  if (window.Drupal?.verticalTabs) {
    installVerticalTabs();
  } else {
    document.addEventListener(
      'drupal:vertical-tabs-ready',
      installVerticalTabs,
      { once: true },
    );
  }
  if (window.Drupal?.DetailsSummarizedContent) {
    installDetailsMembers();
  } else {
    document.addEventListener(
      'drupal:details-summary-ready',
      installDetailsMembers,
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
