/**
 * @file
 * Adapts owned events for explicitly connected jQuery instances.
 * @param {function(HTMLElement): object} instance
 *   The explicitly loaded jQuery instance.
 */

(function (instance) {
  const foreignTriggers = [];
  const legacySlots = new WeakMap();
  const projectedOriginals = new WeakMap();
  // Maps each core-owned special.handle hook to the hook it wraps, if any.
  const coreHandles = new WeakMap();
  // The special.handle hooks this bridge installs for its projections.
  const projectionHandles = new WeakSet();
  // Flags the handler of every slot as core code. The dedicated bridges skip
  // flagged handlers when they look for legacy use.
  const coreHandler = Symbol.for('Drupal.eventBindings.coreHandler');

  /**
   * Records a special.handle hook that core owns.
   *
   * Legacy detection looks through these hooks to the hook each one wraps.
   * @param {function(...unknown): unknown} handle
   *   The installed special.handle hook.
   * @param {function(...unknown): unknown} [previous]
   *   The special.handle hook it wraps, if any.
   * @internal
   */
  function registerCoreSpecialHandle(handle, previous) {
    if (typeof handle === 'function') {
      coreHandles.set(handle, previous);
    }
  }

  // jQuery's own special hooks, such as mouseenter, exist before any other
  // script can use this instance, because this file loads right after it.
  if (typeof instance === 'function') {
    Object.values(instance.event.special).forEach((special) => {
      registerCoreSpecialHandle(special?.handle);
    });
  }

  /**
   * Gives a dedicated bridge the slot reserved in registry order.
   *
   * A marked slot may not be bound yet. The bridge binds it on demand and
   * reports its current state through bound and followsHandlers.
   */
  function claimLegacyEvent(jquery, binding, deliver) {
    const slot = legacySlots.get(jquery)?.get(binding);
    if (slot && !slot.claimed) {
      slot.claimed = true;
      slot.deliver = deliver;
      return slot;
    }
    return undefined;
  }

  /**
   * Splits jQuery event types into raw type names.
   * @param {unknown} types
   *   The types argument of a jQuery event method.
   * @return {string[]}
   *   The raw types, or an empty list for every type.
   */
  function rawTypes(types) {
    return typeof types === 'string'
      ? (types.match(/\S+/g) || [])
          .map((token) => token.split('.')[0])
          .filter(Boolean)
      : [];
  }

  /**
   * Observes the owned bindings using one explicit jQuery instance.
   * @param {function(HTMLElement): object} jquery
   *   The connected jQuery instance.
   * @param {object} registry
   *   The owned event binding registry.
   * @return {function(): void}
   *   Removes this observer's legacy subscriptions.
   * @internal
   */
  function observeLegacyEvents(jquery, registry) {
    const subscriptions = legacySlots.get(jquery) || new Map();
    legacySlots.set(jquery, subscriptions);
    // Every slot's handler is core code, never a contrib handler.
    const slotHandlers = new WeakSet();
    // Marked slots bind only while this instance needs them. Each target maps
    // to its marked slots in registration order.
    const markedSlots = new WeakMap();
    const markedHandlers = new WeakSet();
    // Maps each target to the slots bound when core registers them, such as
    // the States and Ajax slots, in registration order.
    const boundSlots = new WeakMap();
    const anchors = new WeakMap();
    const temporary = new Set();
    // Maps each target to the handler list types that may hold handlers other
    // than jQuery's own and the slots. The add hook below records every
    // binding made after this observer connected, so a target missing here
    // needs no jQuery call. An entry may be stale after a removal; readers
    // verify it.
    const external = new WeakMap();
    let replaying = true;
    let internal = 0;
    let triggerDepth = 0;
    let dispatchDepth = 0;
    let removeDepth = 0;
    let observing = true;
    let settleQueued = false;
    let returnTrue;

    /**
     * Checks for jQuery's internal returnTrue stub.
     *
     * jQuery exposes returnTrue only on a prevented event. The probe event is
     * built on first use, so a page without legacy use makes no jQuery call.
     * @param {function(...unknown): unknown} callback
     *   A handler.
     * @return {boolean}
     *   Whether the handler is jQuery's returnTrue.
     */
    function isReturnTrue(callback) {
      if (!returnTrue) {
        const probe = jquery.Event('drupal:legacy-probe');
        probe.preventDefault();
        returnTrue = probe.isDefaultPrevented;
      }
      return callback === returnTrue;
    }

    /**
     * Returns the handler list type jQuery uses for an event type.
     * @param {string} type
     *   The raw event type.
     * @param {string|undefined} selector
     *   The delegation selector, if any.
     * @return {string}
     *   The handler list type.
     */
    function getBindType(type, selector) {
      const special = jquery.event.special[type];
      return (selector ? special?.delegateType : special?.bindType) || type;
    }

    /**
     * Checks for the leverageNative controller or its trigger stub.
     * @param {object} handleObj
     *   A jQuery handler record.
     * @return {boolean}
     *   Whether jQuery itself added the handler.
     */
    function isInternalHandler(handleObj) {
      return handleObj.namespace === false || isReturnTrue(handleObj.handler);
    }

    /**
     * Returns this instance's handler list for a target and type.
     * @param {EventTarget} target
     *   The target.
     * @param {string} bindType
     *   The handler list type.
     * @return {object[]|undefined}
     *   The handler list, if any.
     */
    function getHandlers(target, bindType) {
      return jquery._data(target, 'events')?.[bindType];
    }

    /**
     * Records the handler list types of an external binding.
     * @param {EventTarget} target
     *   The target.
     * @param {Iterable<string>} bindTypes
     *   The handler list types.
     */
    function track(target, bindTypes) {
      let types = external.get(target);
      if (!types) {
        types = new Set();
        external.set(target, types);
      }
      for (const bindType of bindTypes) {
        types.add(bindType);
      }
    }

    /**
     * Returns the handler list only if an external binding may be in it.
     * @param {EventTarget} target
     *   The target.
     * @param {string} bindType
     *   The handler list type.
     * @return {object[]|undefined}
     *   The handler list, if any.
     */
    function getTrackedHandlers(target, bindType) {
      return external.get(target)?.has(bindType)
        ? getHandlers(target, bindType)
        : undefined;
    }

    /**
     * Checks for handlers other than jQuery's own and the slots.
     * @param {EventTarget} target
     *   The target.
     * @param {string} bindType
     *   The handler list type.
     * @param {boolean} delegated
     *   Whether to look for delegated handlers only.
     * @return {boolean}
     *   Whether such a handler exists.
     */
    function hasExternalHandler(target, bindType, delegated = false) {
      const found = Boolean(
        getTrackedHandlers(target, bindType)?.some(
          (handleObj) =>
            !isInternalHandler(handleObj) &&
            !slotHandlers.has(handleObj.handler) &&
            (!delegated || handleObj.selector),
        ),
      );
      if (!found && !delegated) {
        external.get(target)?.delete(bindType);
      }
      return found;
    }

    /**
     * Returns the latest marked binding for a target and list type.
     * @param {EventTarget} target
     *   The target.
     * @param {string} bindType
     *   The handler list type.
     * @return {object|undefined}
     *   The binding, if any.
     */
    function lastMarkedBinding(target, bindType) {
      let last;
      markedSlots.get(target)?.forEach((slot) => {
        if (slot.bindType === bindType) {
          last = slot.binding;
        }
      });
      return last;
    }

    /**
     * Releases slots whose release waited for a trigger or dispatch to end.
     *
     * A slot whose target still runs a native listener for its type waits
     * again, since jQuery's ordinary dispatch there may still queue it.
     * @param {boolean} settling
     *   Whether no script is on the stack, so every dispatch has ended.
     */
    function settleTemporary(settling = false) {
      if (triggerDepth || dispatchDepth || !temporary.size) {
        return;
      }
      const slots = Array.from(temporary);
      temporary.clear();
      slots.forEach((slot) => {
        if (slot.bound) {
          release(slot.target, slot.bindType, settling);
        }
      });
    }

    /**
     * Settles waiting slots once the running native listener returns.
     *
     * A microtask runs when no script is on the stack, so jQuery's ordinary
     * dispatch that queued the slots has ended.
     */
    function queueSettle() {
      if (!settleQueued) {
        settleQueued = true;
        queueMicrotask(() => {
          settleQueued = false;
          settleTemporary(true);
        });
      }
    }

    /**
     * Checks whether a native listener runs on the target for the type now.
     *
     * jQuery's ordinary dispatch runs inside its own native listener, with
     * window.event set to the native event. The browser sets it for every
     * listener, also on a target outside the document.
     * @param {EventTarget} target
     *   The target.
     * @param {string} bindType
     *   The handler list type.
     * @return {boolean}
     *   Whether a dispatch of that type is at the target.
     */
    function isDispatching(target, bindType) {
      // Known gap: window.event is undefined inside a shadow tree, so a
      // release there does not wait for the dispatch to end.
      const current = window.event;
      return current?.type === bindType && current.currentTarget === target;
    }

    /**
     * Runs jQuery's own handle for an active anchor.
     * @param {object} anchor
     *   The anchor.
     * @param {Event} event
     *   The native event.
     * @return {unknown}
     *   The handle's return value.
     */
    function runAnchor(anchor, event) {
      if (!anchor.active) {
        return undefined;
      }
      const { target, bindType } = anchor;
      const handle = jquery._data(target, 'handle');
      if (!handle || !getHandlers(target, bindType)) {
        idleAnchor(anchor);
        return undefined;
      }
      // jQuery runs the handlers it queued when this dispatch started, so a
      // handler that removes itself, such as one bound with .one(), releases
      // its slots after the dispatch, as during a trigger.
      dispatchDepth += 1;
      try {
        return handle.call(target, event);
      } finally {
        dispatchDepth -= 1;
        settleTemporary();
      }
    }

    /**
     * Moves an inactive anchor behind the latest marked binding.
     *
     * The pinned bindings shared one jQuery listener, created by the first
     * core binding, and every later core binding ran inside it before later
     * contrib handlers. The native core listeners run one after another, so
     * jQuery's dispatch belongs after the last of them. The registry keeps a
     * position listener right after each marked binding's own listener, also
     * for bindings registered before this instance connected. A list type
     * that differs from the binding type, such as mouseover for mouseenter,
     * uses a listener of its own, added now.
     *
     * Known ordering gap: a native listener added between two core bindings
     * runs before every core binding that delivers from jQuery's queue here,
     * such as core's delegate next to a contrib delegate. The pinned shared
     * listener ran all of them before it. The listener's own effects, and a
     * stopImmediatePropagation() call that now suppresses those bindings,
     * follow from that order. Neither anchor position keeps both orders,
     * because core's bindings now have one native listener each.
     * @param {object} anchor
     *   The anchor.
     * @param {object|undefined} binding
     *   The latest marked binding for the target and list type, if any.
     */
    function placeAnchor(anchor, binding) {
      const { target, bindType } = anchor;
      if (anchor.attached) {
        target.removeEventListener(bindType, anchor.listener);
        anchor.attached = false;
      }
      if (binding?.type === bindType) {
        anchor.host = binding;
        return;
      }
      anchor.host = undefined;
      target.addEventListener(bindType, anchor.listener);
      anchor.attached = true;
    }

    /**
     * Makes an anchor idle at the position after core's latest binding.
     *
     * The next handler list jQuery creates then dispatches after every core
     * binding, as the pinned shared listener did.
     * @param {object} anchor
     *   The anchor.
     */
    function idleAnchor(anchor) {
      anchor.active = false;
      placeAnchor(anchor, lastMarkedBinding(anchor.target, anchor.bindType));
    }

    /**
     * Keeps jQuery's dispatch for one target and type at core's position.
     *
     * The pinned bindings created jQuery's native listener when core bound.
     * A marked slot binds later, so jQuery would add its listener after
     * native listeners added in between. The anchor is an idle position
     * after core's listener. While a marked slot created the handler list,
     * the anchor runs jQuery's own handle in its place.
     * @param {object} slot
     *   The marked slot.
     */
    function acquireAnchor(slot) {
      const { target, bindType, binding } = slot;
      let byType = anchors.get(target);
      if (!byType) {
        byType = new Map();
        anchors.set(target, byType);
      }
      let anchor = byType.get(bindType);
      if (!anchor) {
        anchor = {
          target,
          bindType,
          active: false,
          attached: false,
          count: 0,
          host: undefined,
          listener: (event) => runAnchor(anchor, event),
        };
        byType.set(bindType, anchor);
      }
      anchor.count += 1;
      if (!anchor.active) {
        placeAnchor(anchor, binding);
      }
    }

    /**
     * Drops one reference to an anchor and removes it after the last one.
     * @param {object} slot
     *   The marked slot, already removed from the target's slots.
     */
    function releaseAnchor(slot) {
      const { target, bindType, binding } = slot;
      const byType = anchors.get(target);
      const anchor = byType?.get(bindType);
      if (!anchor) {
        return;
      }
      anchor.count -= 1;
      // Known ordering gap: when the host binding is unregistered during a
      // dispatch, before its position listener runs, the browser skips that
      // removed listener and every listener added now. The remaining contrib
      // handlers lose their place in that event and miss it, where the pinned
      // jQuery dispatch ran its queued handlers in order. The registry has no
      // hook to run them in place.
      if (anchor.count > 0) {
        if (anchor.host === binding) {
          // The position left with the binding; the last one remaining hosts.
          placeAnchor(anchor, lastMarkedBinding(target, bindType));
        }
        return;
      }
      byType.delete(bindType);
      if (anchor.attached) {
        target.removeEventListener(bindType, anchor.listener);
      }
      anchor.host = undefined;
      if (!anchor.active) {
        return;
      }
      const handle = jquery._data(target, 'handle');
      if (handle && getHandlers(target, bindType)?.length) {
        // Known ordering gap: the position left with core's last binding.
        // The remaining contrib handlers get a jQuery listener after every
        // native listener on the target, where the pinned listener stayed at
        // the first core binding's position.
        target.addEventListener(bindType, handle);
      }
    }

    /**
     * Binds through jQuery and reports whether jQuery added its listener.
     *
     * jQuery adds its own native listener unless the list type's special
     * setup() returns a value other than false when the list is created.
     * Such a setup delivers the events itself, so an anchor stays idle.
     * @param {EventTarget} target
     *   The target.
     * @param {string} bindType
     *   The handler list type.
     * @param {function(): void} bind
     *   Makes the jQuery binding.
     * @return {boolean}
     *   Whether this call created the list with jQuery's native listener.
     */
    function bindObserved(target, bindType, bind) {
      const created = !getHandlers(target, bindType);
      const special = created ? jquery.event.special[bindType] : undefined;
      const setup = special?.setup;
      let declined = false;
      /**
       * Records whether the setup declines jQuery's native listener.
       * @param {...unknown} args
       *   The arguments jQuery passes to setup().
       * @return {unknown}
       *   The setup's return value.
       */
      function observeSetup(...args) {
        const result = setup.apply(this, args);
        declined = result !== false;
        return result;
      }
      // Known gap: on a frozen special object this wrapper is not installed,
      // so a setup that declines jQuery's listener is not detected.
      if (setup) {
        special.setup = observeSetup;
      }
      try {
        bind();
      } finally {
        if (setup && special.setup === observeSetup) {
          special.setup = setup;
        }
      }
      return created && !declined;
    }

    /**
     * Runs jQuery's dispatch from the anchor in place of its own listener.
     * @param {EventTarget} target
     *   The target.
     * @param {string} bindType
     *   The handler list type.
     */
    function activateAnchor(target, bindType) {
      const anchor = anchors.get(target)?.get(bindType);
      const handle = jquery._data(target, 'handle');
      if (anchor && !anchor.active && handle) {
        target.removeEventListener(bindType, handle);
        anchor.active = true;
      }
    }

    /**
     * Binds a marked slot through jQuery.
     * @param {object} slot
     *   The marked slot.
     * @param {boolean} followsHandlers
     *   Whether the handlers ahead of the slot precede the binding.
     */
    function bindSlot(slot, followsHandlers) {
      const { target, bindType, name, selector } = slot;
      const added = bindObserved(target, bindType, () => {
        internal += 1;
        try {
          jquery(target).on(name, selector, slot.handler);
        } finally {
          internal -= 1;
        }
      });
      slot.bound = true;
      slot.followsHandlers = followsHandlers;
      if (added) {
        activateAnchor(target, bindType);
      }
    }

    /**
     * Removes jQuery's own leftovers so the target has no jQuery listener.
     * @param {EventTarget} target
     *   The target.
     * @param {string} bindType
     *   The handler list type.
     */
    function releaseInternalHandlers(target, bindType) {
      const list = getHandlers(target, bindType);
      if (list?.length && list.every(isInternalHandler)) {
        jquery.event.remove(target, bindType);
      }
    }

    /**
     * Unbinds a marked slot, which stays reserved.
     * @param {object} slot
     *   The marked slot.
     */
    function unbindSlot(slot) {
      const { target, bindType, name, selector } = slot;
      slot.bound = false;
      slot.followsHandlers = false;
      slot.replayBound = false;
      temporary.delete(slot);
      internal += 1;
      slot.releasing = true;
      try {
        jquery(target).off(name, selector, slot.handler);
        releaseInternalHandlers(target, bindType);
      } finally {
        slot.releasing = false;
        internal -= 1;
      }
      const anchor = anchors.get(target)?.get(bindType);
      if (anchor && !getHandlers(target, bindType)) {
        idleAnchor(anchor);
      }
    }

    /**
     * Releases a target's marked slots once no other jQuery handler remains.
     *
     * A later handler materializes them again in front of it, which is the
     * position an unreleased slot would have within jQuery's queue.
     *
     * Known ordering gap: jQuery's listener leaves with the last handler.
     * If it ran before core's native listener, core now runs from its own
     * listener, and a later contrib handler runs at the anchor after it.
     * Both then follow native listeners added between jQuery's old listener
     * and core's, which the pinned shared listener preceded. Such a listener
     * that stops immediate propagation now suppresses core.
     * @param {EventTarget} target
     *   The target.
     * @param {string} bindType
     *   The handler list type.
     * @param {boolean} settling
     *   Whether no script is on the stack, so every dispatch has ended.
     */
    function release(target, bindType, settling = false) {
      const dispatching = !settling && isDispatching(target, bindType);
      if (triggerDepth || dispatchDepth || dispatching) {
        // A handler removed during a trigger or a jQuery dispatch, such as
        // one bound with .one(), releases its slots once the outermost one
        // ends. jQuery still runs the slots it queued for that dispatch.
        markedSlots.get(target)?.forEach((slot) => {
          if (slot.bound && slot.bindType === bindType) {
            temporary.add(slot);
          }
        });
        if (dispatching) {
          queueSettle();
        }
        return;
      }
      if (hasExternalHandler(target, bindType)) {
        return;
      }
      markedSlots.get(target)?.forEach((slot) => {
        // A slot bound behind an earlier slot during a replay keeps its
        // place while that slot stays bound.
        if (
          slot.bound &&
          slot.bindType === bindType &&
          !(
            slot.replayBound &&
            slot.precedingSlots.some((preceding) => preceding.bound)
          )
        ) {
          unbindSlot(slot);
        }
      });
    }

    /**
     * Releases the marked slots of every handler list type on a target.
     * @param {EventTarget} target
     *   The target.
     */
    function releaseTarget(target) {
      const bindTypes = new Set();
      markedSlots.get(target)?.forEach((slot) => {
        if (slot.bound) {
          bindTypes.add(slot.bindType);
        }
      });
      bindTypes.forEach((bindType) => release(target, bindType));
      anchors.get(target)?.forEach((anchor, bindType) => {
        if (anchor.active && !getHandlers(target, bindType)) {
          idleAnchor(anchor);
        }
      });
    }

    /**
     * Binds unbound marked slots on a target whose handler lists match.
     * @param {EventTarget} target
     *   The target.
     * @param {Set<string>|null} bindTypes
     *   The handler list types, or null for all of them.
     * @return {object[]}
     *   The slots bound by this call.
     */
    function materialize(target, bindTypes) {
      const bound = [];
      markedSlots.get(target)?.forEach((slot) => {
        if (!slot.bound && (!bindTypes || bindTypes.has(slot.bindType))) {
          // Behind an earlier slot that is still bound, core keeps its place
          // in jQuery's queue, as when the slot binds at registration.
          bindSlot(
            slot,
            slot.precedingSlots.some((preceding) => preceding.bound),
          );
          bound.push(slot);
        }
      });
      return bound;
    }

    /**
     * Returns the handler list types for a jQuery types argument.
     * @param {unknown} types
     *   The types argument.
     * @param {string|undefined} selector
     *   The delegation selector, if any.
     * @return {Set<string>|null}
     *   The handler list types, or null for every type.
     */
    function bindTypesFor(types, selector) {
      const list = rawTypes(types);
      return list.length
        ? new Set(list.map((type) => getBindType(type, selector)))
        : null;
    }

    const originalAdd = jquery.event.add;
    const add = function (elem, types, handler, data, selector) {
      const handleObj = handler?.handler ? handler : null;
      const callback = handleObj ? handleObj.handler : handler;
      const bindTypes =
        observing &&
        !internal &&
        elem &&
        typeof elem === 'object' &&
        handleObj?.namespace !== false &&
        !markedHandlers.has(callback) &&
        !isReturnTrue(callback)
          ? bindTypesFor(types, handleObj?.selector ?? selector)
          : null;
      if (bindTypes && markedSlots.has(elem)) {
        // Core precedes a later handler, as the pinned binding did. A later
        // slot, such as Ajax's or States', binds at registration and is
        // already a jQuery call, so the earlier slots keep their place in
        // front of it. Accepted cost: with no contrib handler, binding the
        // earlier form slot adds 1 jQuery() and 4 jQuery._data() calls on a
        // target that Ajax or States already binds through jQuery. The
        // Ajax/States eager-slot follow-up removes them.
        materialize(elem, bindTypes);
      }
      const result = originalAdd.apply(this, arguments);
      // A slot, such as States' own, is core code, not an external binding.
      if (bindTypes && !slotHandlers.has(callback)) {
        track(elem, bindTypes);
      }
      return result;
    };
    jquery.event.add = add;

    const originalRemove = jquery.event.remove;
    const remove = function (elem, types, handler, selector) {
      const outer = observing && !internal && !removeDepth && elem;
      if (
        outer &&
        markedSlots.has(elem) &&
        (typeof handler !== 'function' || markedHandlers.has(handler))
      ) {
        // An .off() call that matches core's slot removes core, as pinned.
        materialize(elem, bindTypesFor(types, selector));
      }
      removeDepth += 1;
      try {
        return originalRemove.apply(this, arguments);
      } finally {
        removeDepth -= 1;
        if (outer && markedSlots.has(elem)) {
          releaseTarget(elem);
        }
      }
    };
    jquery.event.remove = remove;

    const originalTrigger = jquery.event.trigger;
    const trigger = function (event, data, elem, onlyHandlers) {
      const type = (typeof event === 'string' ? event : event?.type)?.split(
        '.',
      )[0];
      if (observing && !internal && type) {
        // A synthetic trigger reaches core through its slot, as pinned.
        const special = jquery.event.special[type];
        const direct = new Set([type, special?.bindType || type]);
        const bubbling = new Set([type, special?.delegateType || type]);
        let node = elem || document;
        let first = true;
        while (node) {
          materialize(node, first ? direct : bubbling).forEach((slot) =>
            temporary.add(slot),
          );
          if (onlyHandlers || special?.noBubble) {
            break;
          }
          first = false;
          node =
            node.parentNode ||
            (node.nodeType === Node.DOCUMENT_NODE ? node.defaultView : null);
        }
      }
      triggerDepth += 1;
      try {
        return originalTrigger.apply(this, arguments);
      } finally {
        triggerDepth -= 1;
        settleTemporary();
      }
    };
    jquery.event.trigger = trigger;

    const stop = registry.observe({
      dispatchAt(binding, event) {
        const anchor = anchors.get(binding.target)?.get(binding.type);
        if (anchor?.host === binding) {
          runAnchor(anchor, event);
        }
      },
      add(binding) {
        const { target, type, namespace, selector, listener } = binding;
        if (
          subscriptions.has(binding) ||
          window.Drupal.ajax?.compatibility?.ownsLegacyEvents?.(
            jquery,
            binding,
          ) ||
          // A dedicated adapter reports the bindings it already delivers.
          registry.ownsLegacyEvents?.(jquery, binding)
        ) {
          return;
        }
        // Reserve element slots for the dedicated bridges together: observe()
        // replays all bindings in registration order, regardless of owner.
        const tableSlot =
          jquery === instance &&
          binding.tableInteraction &&
          target instanceof Element &&
          !binding.handlesNativeEvent;
        // An owner marks a binding whose adapter claims the slot later, on
        // every connected instance it supports.
        const legacySlot = Boolean(binding.reservesLegacySlot) && !tableSlot;
        // A select has no native change() method for jQuery's trigger to invoke.
        const selectChange =
          !selector && type === 'change' && target instanceof HTMLSelectElement;
        const directActivation =
          !selector &&
          ((type === 'click' && target instanceof HTMLAnchorElement) ||
            (type === 'submit' && target instanceof HTMLFormElement));
        const forwardsSynthetic =
          binding.ajax ||
          binding.states ||
          type === 'compatibility:probe' ||
          directActivation ||
          selectChange;
        if (!forwardsSynthetic && !tableSlot && !legacySlot) {
          return;
        }

        const name = namespace ? `${type}.${namespace}` : type;

        /**
         * Routes one synthetic invocation to the owned operation.
         * @param {object} event
         *   The active jQuery event.
         * @param {...unknown} args
         *   The positional arguments from the trigger.
         * @return {unknown}
         *   The result jQuery should use for this handler.
         */
        function deliver(event, ...args) {
          // The pinned jQuery trigger sets isTrigger for both trigger variants.
          // A DOM event already reaches the owned listener directly.
          if (
            !forwardsSynthetic ||
            !event.isTrigger ||
            projectedOriginals.has(event) ||
            (!binding.ajax &&
              (event.originalEvent ||
                (directActivation && event.target !== target)))
          ) {
            return undefined;
          }

          if (binding.ajax && window.Drupal.ajax?.compatibility) {
            return listener.call(this, event, ...args);
          }

          // Standalone Ajax bindings still receive a native event shape.
          // Preserve the legacy metadata for other owned registrations.
          const projected = binding.ajax
            ? new Event(event.type, { cancelable: true })
            : Object.create(event);
          Object.defineProperties(projected, {
            target: { value: event.target },
            currentTarget: { value: event.currentTarget },
            key: { value: event.key },
            which: { value: event.which },
            submitter: {
              value: event.submitter ?? event.originalEvent?.submitter,
            },
            isTrigger: { value: event.isTrigger },
            defaultPrevented: { get: () => event.isDefaultPrevented() },
            preventDefault: { value: () => event.preventDefault() },
            stopPropagation: { value: () => event.stopPropagation() },
            stopImmediatePropagation: {
              value: () => event.stopImmediatePropagation(),
            },
          });
          if (binding.states && !projected.detail) {
            if (type.startsWith('state:')) {
              window.Drupal.states?.compatibility?.warnSyntheticState?.(type);
            }
            Object.defineProperty(projected, 'detail', {
              value: {
                value: event.value,
                oldValue: event.oldValue,
                trigger: event.trigger,
              },
            });
          }
          return listener.call(this, projected, ...args);
        }

        const bindType = getBindType(type, selector);
        // Accepted gap A: a handler bound between the jQuery file and this
        // file is not recorded. A late connection replays without reading
        // jQuery's data, so it makes no jQuery call on a page without legacy
        // handlers. Every handler such a read could find was bound inside
        // this gap. A marked slot binds only before a later recorded handler,
        // and its order relative to the unrecorded one is unknown.
        const list = getTrackedHandlers(target, bindType);
        const slot = {
          deliver,
          binding,
          target,
          bindType,
          name,
          selector,
          bound: false,
          // Whether handlers ahead of this slot precede the binding in
          // registration order. When core registers the binding, every
          // existing handler does, and this instance dispatches before core's
          // native listener. During a replay, only earlier slots do; the order
          // of other existing handlers is unknown.
          followsHandlers: false,
          handler(...args) {
            return slot.deliver.apply(this, args);
          },
          /**
           * Checks whether core delivers real events from this slot.
           *
           * Behind handlers that precede the binding, and whenever a contrib
           * delegate shares the list: jQuery runs delegates on descendants
           * first, so only its queue keeps their pinned order with core.
           * @return {boolean}
           *   Whether the slot, not core's native listener, delivers.
           */
          deliversRealEvents() {
            return (
              slot.bound &&
              (slot.followsHandlers ||
                hasExternalHandler(target, bindType, true))
            );
          },
          remove(unbind = true) {
            subscriptions.delete(binding);
            if (!legacySlot) {
              slot.bound = false;
              boundSlots.get(target)?.delete(slot);
              if (unbind) {
                jquery(target).off(name, selector, slot.handler);
              }
              return;
            }
            if (slot.bound) {
              if (unbind) {
                unbindSlot(slot);
              } else {
                slot.bound = false;
                temporary.delete(slot);
              }
            }
            markedSlots.get(target)?.delete(slot);
            releaseAnchor(slot);
          },
        };
        slotHandlers.add(slot.handler);
        Object.defineProperty(slot.handler, coreHandler, { value: true });
        subscriptions.set(binding, slot);
        const followsHandlers = Boolean(
          list?.some(
            (handleObj) =>
              !isInternalHandler(handleObj) &&
              (!replaying || slotHandlers.has(handleObj.handler)),
          ),
        );
        if (!legacySlot) {
          const bind = () => jquery(target).on(name, selector, slot.handler);
          // During a replay, jQuery's listener for a list this binding creates
          // follows every native listener on the target. A marked slot behind
          // this one moves it to its anchor. Outside a replay, the binding
          // reads no jQuery data.
          if (replaying) {
            slot.addedListener =
              bindObserved(target, bindType, bind) &&
              !anchors.get(target)?.get(bindType)?.active;
          } else {
            bind();
          }
          slot.bound = true;
          slot.followsHandlers = followsHandlers;
          if (!boundSlots.has(target)) {
            boundSlots.set(target, new Set());
          }
          boundSlots.get(target).add(slot);
          return;
        }

        // A marked slot binds now only behind existing handlers other than
        // slots. Otherwise it binds right before the first later handler, a
        // synthetic trigger, or an .off() call that could match it.
        markedHandlers.add(slot.handler);
        // The earlier slots in this list, which precede the binding.
        slot.precedingSlots = Array.from(boundSlots.get(target) ?? []).filter(
          (preceding) => preceding.bindType === bindType,
        );
        if (!markedSlots.has(target)) {
          markedSlots.set(target, new Set());
        }
        markedSlots.get(target).add(slot);
        acquireAnchor(slot);
        // Accepted gap A: a handler bound between the jQuery file and this
        // file runs first, with an unknown order relative to core.
        // Accepted gap B: a foreign handler bound before connectLegacy() has
        // no pinned equivalent; it also stays ahead of core's slot.
        // During a replay, core binds behind an earlier slot, such as Ajax's,
        // which delivers real events from jQuery's queue. That target already
        // has core's jQuery binding, and the check reads no jQuery data.
        // Accepted cost: with no contrib handler, binding this slot calls
        // jQuery() and jQuery._data() on a target that Ajax or States already
        // binds through jQuery. The Ajax/States eager-slot follow-up removes
        // these calls.
        const behindSlot =
          replaying && slot.precedingSlots.some((preceding) => preceding.bound);
        if (
          behindSlot ||
          list?.some(
            (handleObj) =>
              !isInternalHandler(handleObj) &&
              !slotHandlers.has(handleObj.handler),
          )
        ) {
          bindSlot(slot, followsHandlers || behindSlot);
          slot.replayBound = behindSlot;
          if (
            behindSlot &&
            slot.precedingSlots.some((preceding) => preceding.addedListener)
          ) {
            // The earlier slot's jQuery listener follows every native
            // listener on the target. The pinned listener sat at core's first
            // binding, ahead of native listeners added later. The anchor
            // after this binding runs the queue, earlier slot first, ahead of
            // them. Known ordering gap: a native listener added between the
            // earlier slot's binding and this one still runs first.
            activateAnchor(target, bindType);
          }
        }
      },
      remove(binding) {
        const slot = subscriptions.get(binding);
        if (slot && !slot.claimed) {
          slot.remove();
        }
      },
    });
    replaying = false;
    return () => {
      stop();
      observing = false;
      if (jquery.event.add === add) {
        jquery.event.add = originalAdd;
      }
      if (jquery.event.remove === remove) {
        jquery.event.remove = originalRemove;
      }
      if (jquery.event.trigger === trigger) {
        jquery.event.trigger = originalTrigger;
      }
    };
  }

  /**
   * Projects explicitly marked native events at jQuery's handler dispatch.
   * @param {object} jquery
   *   The explicitly connected jQuery instance.
   * @param {string} type
   *   The unmapped event type. Install one projection per instance and type.
   * @param {{get: function(Event): (object|undefined)}} payloads
   *   Maps native CustomEvents to objects with optional properties and args.
   * @return {function(): void}
   *   Disconnects the projection.
   * @internal
   */
  function observeNativePayloads(jquery, type, payloads) {
    const specials = jquery.event.special;
    const existing = specials[type];
    const special = existing ?? {};
    const previousHandle = special.handle;
    const hadHandle = Object.hasOwn(special, 'handle');
    const projected = new WeakSet();
    let connected = true;

    /**
     * Supplies the declared payload to the existing handler.
     * @param {object} event
     *   The active jQuery event.
     * @param {...unknown} args
     *   The arguments already supplied by jQuery.
     * @return {unknown}
     *   The downstream handler's return value.
     */
    function handle(event, ...args) {
      const payload =
        connected &&
        !event.isTrigger &&
        event.handleObj.origType === type &&
        event.originalEvent?.type === type &&
        payloads.get(event.originalEvent);
      if (payload && !projected.has(event)) {
        // Preserve writes by earlier handlers on this jQuery event.
        Object.assign(event, payload.properties);
        projected.add(event);
      }
      return (previousHandle || event.handleObj.handler).call(
        this,
        event,
        ...(payload?.args ?? args),
      );
    }

    special.handle = handle;
    registerCoreSpecialHandle(handle, previousHandle);
    projectionHandles.add(handle);
    if (!existing) {
      specials[type] = special;
    }
    return () => {
      connected = false;
      // A later hook may wrap this handler. Leave it in place and pass through.
      if (special.handle !== handle) {
        return;
      }
      if (hadHandle) {
        special.handle = previousHandle;
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
  }

  /**
   * Projects States publications as one synthetic jQuery event per instance.
   * @param {object} jquery
   *   The explicitly connected jQuery instance.
   * @param {string} type
   *   The unmapped event type.
   * @param {WeakMap} activeSynthetic
   *   Maps active native events to their projection record.
   * @return {function(): void}
   *   Disconnects the synthetic projection.
   * @internal
   */
  function observeSyntheticProjection(jquery, type, activeSynthetic) {
    const specials = jquery.event.special;
    const existing = specials[type];
    const special = existing ?? {};
    const previousHandle = special.handle;
    const hadHandle = Object.hasOwn(special, 'handle');
    let connected = true;

    /**
     * Delivers one synthetic event without originalEvent across targets.
     * @param {object} event
     *   The active jQuery event.
     * @param {...unknown} args
     *   Additional handler arguments.
     * @return {unknown}
     *   The downstream handler's return value.
     */
    function handle(event, ...args) {
      const original = event.originalEvent;
      const record =
        connected &&
        original &&
        !event.isTrigger &&
        event.handleObj.origType === type &&
        original.type === type
          ? activeSynthetic.get(original)
          : undefined;
      if (!record) {
        return (previousHandle || event.handleObj.handler).call(
          this,
          event,
          ...args,
        );
      }

      let { shared } = record;
      if (!shared) {
        shared = record.properties
          ? jquery.Event(event.type, record.properties)
          : jquery.Event(event.type);
        shared.target = event.target;
        shared.isTrigger = 3;
        record.shared = shared;
        projectedOriginals.set(shared, original);
      }

      shared.currentTarget = event.currentTarget;
      shared.delegateTarget = event.delegateTarget;
      shared.handleObj = event.handleObj;
      shared.data = event.data;
      // A pinned .trigger() without namespaces set an empty namespace.
      shared.namespace = event.namespace || '';

      // Start from the current payload so an edit made through another
      // instance is visible, and remember it to detect this handler's edits.
      const { detail } = original;
      const fields =
        record.syncState && detail && typeof detail === 'object'
          ? ['value', 'oldValue', 'trigger']
          : [];
      const before = {};
      for (const field of fields) {
        if (Object.hasOwn(detail, field) && shared[field] !== detail[field]) {
          shared[field] = detail[field];
        }
        before[field] = shared[field];
      }

      const result = (previousHandle || event.handleObj.handler).call(
        this,
        shared,
        ...args,
      );
      if (result === false) {
        shared.preventDefault();
        shared.stopPropagation();
      }
      if (shared.isDefaultPrevented()) {
        event.preventDefault();
      }
      if (shared.isImmediatePropagationStopped()) {
        event.stopImmediatePropagation();
      } else if (shared.isPropagationStopped()) {
        event.stopPropagation();
      }
      if (result !== undefined) {
        shared.result = result;
      }
      // Write back only what this handler changed, so a passive instance
      // never restores a value another instance already edited.
      for (const field of fields) {
        if (shared[field] !== before[field]) {
          detail[field] = shared[field];
        }
      }
      return result;
    }

    special.handle = handle;
    registerCoreSpecialHandle(handle, previousHandle);
    projectionHandles.add(handle);
    if (!existing) {
      specials[type] = special;
    }
    return () => {
      connected = false;
      if (special.handle !== handle) {
        return;
      }
      if (hadHandle) {
        special.handle = previousHandle;
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
  }

  /**
   * Reports whether a special.handle hook that core does not own exists.
   * @param {object} jquery
   *   The connected jQuery instance.
   * @param {string} type
   *   The event type.
   * @return {boolean}
   *   Whether jQuery dispatch runs handlers through a legacy hook.
   * @internal
   */
  function hasLegacySpecialHandle(jquery, type) {
    let handle = jquery.event.special[type]?.handle;
    while (coreHandles.has(handle)) {
      handle = coreHandles.get(handle);
    }
    return typeof handle === 'function';
  }

  /**
   * Lets a callback see each native state:* event before jQuery dispatches it.
   * @param {object} jquery
   *   The explicitly connected jQuery instance.
   * @param {function(CustomEvent): void} callback
   *   Receives the native event.
   * @return {function(): void}
   *   Stops observing.
   */
  function observeNativeStateDispatch(jquery, callback) {
    const previous = jquery.event.dispatch;
    let connected = true;

    /**
     * Reports a native state:* event, then runs jQuery's dispatch.
     * @param {Event|object} nativeEvent
     *   The event jQuery dispatches.
     * @return {unknown}
     *   The result of jQuery's dispatch.
     */
    function dispatch(nativeEvent) {
      if (
        connected &&
        nativeEvent instanceof CustomEvent &&
        nativeEvent.type.startsWith('state:')
      ) {
        callback(nativeEvent);
      }
      return previous.apply(this, arguments);
    }
    jquery.event.dispatch = dispatch;
    return () => {
      connected = false;
      if (jquery.event.dispatch === dispatch) {
        jquery.event.dispatch = previous;
      }
    };
  }

  /**
   * Copies the defined state fields of a payload.
   * @param {object} [detail]
   *   The native event detail.
   * @return {object}
   *   The value, oldValue and trigger fields that are not undefined.
   */
  function stateProperties(detail) {
    const properties = {};
    for (const field of ['value', 'oldValue', 'trigger']) {
      const value =
        detail && typeof detail === 'object' && Object.hasOwn(detail, field)
          ? detail[field]
          : undefined;
      // jQuery's event constructor skips undefined properties.
      if (value !== undefined) {
        properties[field] = value;
      }
    }
    return properties;
  }

  /**
   * Projects the legacy payloads of supported owned publications.
   * @param {object} jquery
   *   The explicitly connected jQuery instance.
   * @param {object} registry
   *   The owned event registry.
   * @return {function(): void}
   *   Stops observing publications and removes the projections.
   */
  function observeOwnedPublications(jquery, registry) {
    const payloads = new WeakMap();
    const activeSynthetic = new WeakMap();
    const projections = new Map();
    // A state:* event dispatched natively, outside the registry, carries its
    // payload in detail. A legacy hook receives it like a registry
    // publication. Without one, jQuery handlers get the plain event, as the
    // pinned jQuery dispatch gave them.
    const projectedPayloads = {
      get(event) {
        if (payloads.has(event)) {
          return payloads.get(event);
        }
        if (
          event instanceof CustomEvent &&
          event.type.startsWith('state:') &&
          !activeSynthetic.has(event) &&
          hasLegacySpecialHandle(jquery, event.type)
        ) {
          return { properties: stateProperties(event.detail) };
        }
        return undefined;
      },
    };

    /**
     * Installs a projection, or wraps a hook that replaced it again.
     *
     * A library loaded later, for example through Ajax, may replace the
     * special.handle hook. Wrapping it again gives it the pinned event.
     * @param {string} key
     *   The projection key.
     * @param {string} type
     *   The event type.
     * @param {function(): function(): void} install
     *   Installs the projection and returns its disconnect function.
     */
    function project(key, type, install) {
      const current = projections.get(key);
      if (current) {
        // Other projections of this bridge may sit above this one. Only a
        // hook the bridge does not own needs the projection above it.
        let handle = jquery.event.special[type]?.handle;
        while (handle !== current.handle && projectionHandles.has(handle)) {
          handle = coreHandles.get(handle);
        }
        if (handle === current.handle) {
          return;
        }
        // The replaced projection stays in any chain as a pass-through.
        current.disconnect();
        projections.delete(key);
      }
      const disconnect = install();
      projections.set(key, {
        disconnect,
        handle: jquery.event.special[type].handle,
      });
    }

    const stop = registry.observePublications((event) => {
      const { type, detail } = event;
      const state = type.startsWith('state:');
      const statesSynthetic =
        window.Drupal.states?.compatibility?.isSyntheticTrigger?.(event) ||
        (state && window.Drupal.states?.compatibility?.isStateEvent?.(event));
      if (statesSynthetic) {
        if (activeSynthetic.has(event)) {
          return undefined;
        }
        const properties = state ? stateProperties(detail) : undefined;
        const key = `synthetic:${type}`;
        project(key, type, () =>
          observeSyntheticProjection(jquery, type, activeSynthetic),
        );
        activeSynthetic.set(event, {
          properties,
          syncState: state,
          shared: null,
        });
        return () => activeSynthetic.delete(event);
      }
      if ((!state && type !== 'columnschange') || payloads.has(event)) {
        return undefined;
      }

      const payload = state
        ? { properties: stateProperties(detail) }
        : { args: [Boolean(detail?.displayWeight)] };

      const key = `payload:${type}`;
      project(key, type, () =>
        observeNativePayloads(jquery, type, projectedPayloads),
      );
      payloads.set(event, payload);
      return () => payloads.delete(event);
    });

    const stopNative = observeNativeStateDispatch(jquery, (event) => {
      const { type } = event;
      if (
        !payloads.has(event) &&
        !activeSynthetic.has(event) &&
        hasLegacySpecialHandle(jquery, type)
      ) {
        project(`payload:${type}`, type, () =>
          observeNativePayloads(jquery, type, projectedPayloads),
        );
      }
    });

    return () => {
      stop();
      stopNative();
      Array.from(projections.values())
        .reverse()
        .forEach(({ disconnect }) => disconnect());
      projections.clear();
    };
  }

  /**
   * Tracks native default actions caused by an explicitly connected copy.
   */
  function observeForeignTriggers(jquery) {
    const previous = jquery.event.trigger;
    let connected = true;
    function trigger(event, data, target) {
      if (!connected) {
        return previous.apply(this, arguments);
      }
      const type = (typeof event === 'string' ? event : event.type).split(
        '.',
      )[0];
      foreignTriggers.push({ jquery, type, target: target || document });
      try {
        return previous.apply(this, arguments);
      } finally {
        foreignTriggers.pop();
      }
    }
    jquery.event.trigger = trigger;
    return () => {
      connected = false;
      if (jquery.event.trigger === trigger) {
        jquery.event.trigger = previous;
      }
    };
  }

  /**
   * Identifies the real event that accompanies a foreign synthetic dispatch.
   */
  function isForeignDefaultAction(event) {
    const frame = foreignTriggers.at(-1);
    if (frame?.type !== event.type || frame.target !== event.target) {
      return false;
    }
    const { jquery } = frame;
    // The dedicated request bridge tracks its own leveraged dispatch.
    if (window.Drupal.ajax?.compatibility?.ownsLegacyEvents?.(jquery)) {
      return false;
    }
    const phase = ['click', 'focus', 'blur'].includes(event.type)
      ? jquery._data(event.target, event.type)
      : null;
    if (
      jquery.event.triggered === event.type ||
      Array.isArray(phase) ||
      (phase && Object.hasOwn(phase, 'value'))
    ) {
      frame.defaultEvent = frame.defaultEvent || event;
      return frame.defaultEvent === event;
    }
    return false;
  }

  const connections = new WeakMap();
  // Connected instances in connection order, and their observers.
  const connected = new Set();
  const connectionObservers = new Set();

  /**
   * Runs one connection observer callback without interrupting the others.
   * @param {object} observer
   *   The connection observer.
   * @param {string} method
   *   The callback name, connect or disconnect.
   * @param {object} jquery
   *   The jQuery instance.
   */
  function notifyConnection(observer, method, jquery) {
    try {
      observer[method](jquery);
    } catch (error) {
      window.Drupal.throwError(error);
    }
  }

  /**
   * Observes connected jQuery instances and subsequent changes.
   * @param {object} observer
   *   An adapter with connect and disconnect methods for one instance.
   * @return {function(): void}
   *   Stops observing and disconnects the adapter from every instance.
   * @internal
   */
  function observeLegacyConnections(observer) {
    if (!connectionObservers.has(observer)) {
      connectionObservers.add(observer);
      connected.forEach((jquery) =>
        notifyConnection(observer, 'connect', jquery),
      );
    }
    return () => {
      if (connectionObservers.delete(observer)) {
        connected.forEach((jquery) =>
          notifyConnection(observer, 'disconnect', jquery),
        );
      }
    };
  }

  /**
   * Connects one explicitly selected jQuery instance to owned events.
   * @param {object} jquery
   *   The jQuery instance to connect.
   * @return {function(): void}
   *   Disconnects the instance and removes its subscriptions.
   */
  function connect(jquery) {
    if (typeof jquery !== 'function') {
      return () => undefined;
    }

    if (!connections.has(jquery)) {
      const registry = window.Drupal.eventBindings;
      const stopTriggers = observeForeignTriggers(jquery);
      const stopBindings = observeLegacyEvents(jquery, registry);
      const stopPublications = observeOwnedPublications(jquery, registry);
      const disconnect = () => {
        if (connections.get(jquery) === disconnect) {
          // Observers release their claimed slots before the bridge stops.
          connected.delete(jquery);
          connectionObservers.forEach((observer) =>
            notifyConnection(observer, 'disconnect', jquery),
          );
          // Unwrap in the reverse order of wrapping.
          stopPublications();
          stopBindings();
          stopTriggers();
          connections.delete(jquery);
        }
      };
      connections.set(jquery, disconnect);
      // Slots are reserved above, so observers claim them in registry order.
      connected.add(jquery);
      connectionObservers.forEach((observer) =>
        notifyConnection(observer, 'connect', jquery),
      );
    }
    return connections.get(jquery);
  }

  /**
   * Allows explicit opt-in and connects the supplied instance.
   */
  function initialize() {
    const registry = window.Drupal.eventBindings;
    registry.connectLegacy = registry.connectLegacy ?? connect;
    registry.claimLegacyEvent = registry.claimLegacyEvent ?? claimLegacyEvent;
    registry.observeLegacyConnections =
      registry.observeLegacyConnections ?? observeLegacyConnections;
    registry.hasLegacySpecialHandle =
      registry.hasLegacySpecialHandle ?? hasLegacySpecialHandle;
    registry.registerCoreSpecialHandle =
      registry.registerCoreSpecialHandle ?? registerCoreSpecialHandle;
    registry.isForeignDefaultAction =
      registry.isForeignDefaultAction || isForeignDefaultAction;
    registry.getProjectedOriginal =
      registry.getProjectedOriginal ??
      ((event) => projectedOriginals.get(event));
    registry.connectLegacy(instance);
  }

  if (window.Drupal?.eventBindings) {
    initialize();
  } else {
    document.addEventListener('drupal:event-bindings-ready', initialize, {
      once: true,
    });
  }
})(window.jQuery);
