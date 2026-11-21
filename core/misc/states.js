/**
 * @file
 * Drupal's states library.
 * @param {object} Drupal
 *   The global Drupal namespace.
 * @param {function(string, string, Element=): Element[]} once
 *   The once utility function.
 */

(function (Drupal, once) {
  /**
   * The base States namespace.
   *
   * Having the local states variable allows us to use the States namespace
   * without having to always declare "Drupal.states".
   * @namespace Drupal.states
   */
  const states = {
    /**
     * An array of functions that should be postponed.
     */
    postponed: [],
  };

  Drupal.states = states;

  const nonTextInputTypes = [
    'button',
    'checkbox',
    'color',
    'date',
    'datetime-local',
    'email',
    'file',
    'hidden',
    'image',
    'month',
    'number',
    'password',
    'radio',
    'range',
    'reset',
    'search',
    'submit',
    'tel',
    'time',
    'url',
    'week',
  ];
  const formPseudos = {
    input: 'input, select, textarea, button',
    radio: 'input[type="radio" i]',
    checkbox: 'input[type="checkbox" i]',
    text: `input:not(${nonTextInputTypes.map((type) => `[type="${type}" i]`).join(', ')})`,
    password: 'input[type="password" i]',
    file: 'input[type="file" i]',
    submit:
      'input[type="submit" i], button[type="submit" i], button:not([type="button" i], [type="reset" i], [command], [commandfor])',
    button: 'button, input[type="button" i]',
    image: 'input[type="image" i]',
    reset: 'input[type="reset" i], button[type="reset" i]',
  };
  const nativePseudos = new Set([
    'active',
    'any-link',
    'autofill',
    'blank',
    'checked',
    'default',
    'defined',
    'dir',
    'disabled',
    'empty',
    'enabled',
    'first-child',
    'first-of-type',
    'focus',
    'focus-visible',
    'focus-within',
    'fullscreen',
    'hover',
    'in-range',
    'indeterminate',
    'invalid',
    'is',
    'lang',
    'last-child',
    'last-of-type',
    'link',
    'local-link',
    'modal',
    'not',
    'nth-child',
    'nth-last-child',
    'nth-last-of-type',
    'nth-of-type',
    'only-child',
    'only-of-type',
    'open',
    'optional',
    'out-of-range',
    'placeholder-shown',
    'popover-open',
    'read-only',
    'read-write',
    'required',
    'root',
    'scope',
    'target',
    'target-within',
    'user-invalid',
    'user-valid',
    'valid',
    'visited',
    'where',
  ]);
  const deprecatedSelectors = new Set();

  /**
   * Emits a selector deprecation once per selector string.
   * @param {string} selector
   *   The original selector string.
   */
  function deprecateSelector(selector) {
    if (!deprecatedSelectors.has(selector)) {
      deprecatedSelectors.add(selector);
      Drupal.deprecationError({
        message: `The jQuery selector "${selector}" in States is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use a CSS selector and the native States callback arguments. See https://www.drupal.org/node/CHANGE_RECORD.`,
      });
    }
  }

  /**
   * Normalizes the audited form pseudos in a States selector.
   *
   * Skip escapes, quoted strings and attributes as tokens. Inspect every
   * selector in functional pseudos, including forgiving selector lists.
   * @param {string} selector
   *   A selector from the final States markup.
   * @return {{selector: string, translated: boolean}|null}
   *   A native selector result, or null for selectors that need the legacy
   *   engine.
   */
  function nativeSelector(selector) {
    const tokens =
      /\\(?:[\da-f]{1,6}[\t\n\r\f ]?|[^\n\r\f])|\[(?:\\.|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|[^\]"'\\])*\]|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|:[\w-]*|\/\*/gis;
    let eligible = true;
    let translated = false;
    const normalized = selector.replace(tokens, (token, offset) => {
      if (token === '/*') {
        eligible = false;
        return token;
      }
      if (token[0] !== ':') {
        return token;
      }
      const name = token.slice(1).toLowerCase();
      const next = selector[offset + token.length];
      if (!name || next === '\\' || (next && next.charCodeAt(0) > 127)) {
        eligible = false;
        return token;
      }
      if (Object.hasOwn(formPseudos, name)) {
        translated = true;
        // Wrap the substitution so it can follow any simple selector.
        return `:is(${formPseudos[name]})`;
      }
      eligible = eligible && nativePseudos.has(name);
      return token;
    });
    return eligible ? { selector: normalized, translated } : null;
  }

  /**
   * Resolves an audited selector against the document.
   * @param {string} selector
   *   The original selector from the States markup.
   * @return {Element[]|null}
   *   The matches, or null when a legacy selector has no attached adapter.
   */
  function select(selector) {
    const normalized = nativeSelector(selector);
    if (normalized !== null) {
      try {
        const simpleId = /^#([\w-]+)$/.exec(normalized.selector);
        const matches = simpleId
          ? [document.getElementById(simpleId[1])].filter(Boolean)
          : Array.from(document.querySelectorAll(normalized.selector));
        if (normalized.translated) {
          deprecateSelector(selector);
        }
        return matches;
      } catch (error) {
        if (error.name !== 'SyntaxError') {
          throw error;
        }
      }
    }
    return states.compatibility.select?.(selector) ?? null;
  }

  /**
   * Adapter hooks owned by the deprecated States bridge.
   * @internal
   */
  states.compatibility = {};

  /**
   * Binds native States events and exposes the slot to the event bridge.
   * @param {EventTarget} target
   *   The event target.
   * @param {string} type
   *   One or more whitespace-separated event names, optionally with namespaces.
   * @param {function(Event): void} listener
   *   The native listener.
   * @param {object} [data]
   *   Binding data used by the legacy adapter.
   * @return {function(): void}
   *   Removes the binding.
   */
  function listen(target, type, listener, data) {
    const names = String(type).trim().split(/\s+/).filter(Boolean);
    const removers = [];
    for (const entry of names) {
      const [baseType, ...namespaces] = entry.split('.');
      if (baseType) {
        const namespace = namespaces.filter(Boolean).sort().join('.');
        const binding = {
          target,
          type: baseType,
          ...(namespace ? { namespace } : {}),
          listener,
          data,
          states: true,
        };
        const nativeListener = (event) => {
          const syntheticDefault =
            Drupal.ajax?.compatibility?.isSyntheticDefaultAction?.(event) ??
            Drupal.eventBindings.isForeignDefaultAction?.(event);
          if (!syntheticDefault && !binding.handlesNativeEvent?.(event)) {
            return listener(event);
          }
        };
        target.addEventListener(baseType, nativeListener);
        const unregister = Drupal.eventBindings.register(binding);
        removers.push(() => {
          unregister();
          target.removeEventListener(baseType, nativeListener);
        });
      }
    }
    return () => {
      removers.forEach((remove) => remove());
    };
  }

  const stateEvents = new WeakSet();
  states.compatibility.isStateEvent = (event) => stateEvents.has(event);

  // The original target of each event States publishes.
  const publicationTargets = new WeakMap();
  states.compatibility.publicationTarget = (event) =>
    publicationTargets.get(event);

  /**
   * Publishes one owned event and records its original target.
   * @param {EventTarget} target
   *   The dispatch target.
   * @param {Event} event
   *   The event to publish.
   */
  function publish(target, event) {
    publicationTargets.set(event, target);
    Drupal.eventBindings.publish(target, event);
  }

  /**
   * Publishes a state change on each original target.
   * @param {Iterable<Element>} elements
   *   The targets of the state change.
   * @param {string} type
   *   The full state event name.
   * @param {object} detail
   *   The state values and trigger flag.
   */
  function publishState(elements, type, detail) {
    for (const element of elements) {
      const event = new CustomEvent(type, {
        bubbles: true,
        cancelable: true,
        detail: { ...detail },
      });
      stateEvents.add(event);
      publish(element, event);
    }
  }

  /**
   * Inverts a (if it's not undefined) when invertState is true.
   * @function Drupal.states~invert
   * @param {unknown} a
   *   The value to maybe invert.
   * @param {boolean} invertState
   *   Whether to invert state or not.
   * @return {boolean}
   *   The result.
   */
  function invert(a, invertState) {
    return invertState && typeof a !== 'undefined' ? !a : a;
  }

  /**
   * Compares two values while ignoring undefined values.
   * @function Drupal.states~compare
   * @param {unknown} a
   *   Value a.
   * @param {unknown} b
   *   Value b.
   * @return {boolean}
   *   The comparison result.
   */
  function compare(a, b) {
    if (a === b) {
      return typeof a === 'undefined' ? a : true;
    }

    return typeof a === 'undefined' || typeof b === 'undefined';
  }

  /**
   * Bitwise AND with a third undefined state.
   * @function Drupal.states~ternary
   * @param {unknown} a
   *   Value a.
   * @param {unknown} b
   *   Value b
   * @return {boolean}
   *   The result.
   */
  function ternary(a, b) {
    if (typeof a === 'undefined') {
      return b;
    }
    if (typeof b === 'undefined') {
      return a;
    }

    return a && b;
  }

  const attached = new WeakMap();
  const pending = new Set();
  const elementCleanups = new WeakMap();
  const trackedTargets = new Set();

  /**
   * Registers a cleanup callback to run if a dependee element unloads.
   * @param {EventTarget} target
   *   The element being observed.
   * @param {function(): void} cleanup
   *   The cleanup callback to invoke on unload or disconnect.
   * @return {function(): void}
   *   Stops tracking this cleanup callback.
   */
  function trackTargetCleanup(target, cleanup) {
    if (!(target instanceof Element)) {
      return () => {};
    }
    let entry = elementCleanups.get(target);
    if (!entry) {
      entry = { target, cleanups: new Set() };
      elementCleanups.set(target, entry);
      trackedTargets.add(entry);
    }
    entry.cleanups.add(cleanup);
    return () => {
      entry.cleanups.delete(cleanup);
      if (!entry.cleanups.size && elementCleanups.get(target) === entry) {
        elementCleanups.delete(target);
        trackedTargets.delete(entry);
      }
    };
  }

  /**
   * Releases all States listeners and trigger references for a detached target.
   * @param {object} entry
   *   The tracked target record.
   */
  function unloadTarget(entry) {
    trackedTargets.delete(entry);
    elementCleanups.delete(entry.target);
    const cleanups = Array.from(entry.cleanups);
    entry.cleanups.clear();
    cleanups.forEach((cleanup) => cleanup());
  }

  /**
   * Releases tracked dependee targets contained within an unloading context.
   *
   * Targets outside the context stay tracked, even when disconnected.
   * @param {Document|Element|DocumentFragment} context
   *   The unloading context.
   */
  function unloadTargetsInContext(context) {
    Array.from(trackedTargets).forEach((entry) => {
      if (
        context === document ||
        (context instanceof Node && context.contains(entry.target))
      ) {
        unloadTarget(entry);
      }
    });
  }

  const awaitingRemoval = new Map();
  let removalObserver = null;

  /**
   * Runs the release callbacks of watched elements that left the document.
   */
  function releaseRemoved() {
    awaitingRemoval.forEach((releases, element) => {
      if (!element.isConnected) {
        awaitingRemoval.delete(element);
        releases.forEach((release) => release());
      }
    });
    if (!awaitingRemoval.size) {
      removalObserver?.disconnect();
      removalObserver = null;
    }
  }

  /**
   * Calls a release callback once an unloaded element leaves the document.
   *
   * Drupal detaches behaviors before it removes the old markup, so the
   * element is usually still connected when its unload detach runs.
   * @param {Element} element
   *   The unloaded element.
   * @param {function(): void} release
   *   The release callback.
   */
  function releaseOnRemoval(element, release) {
    if (!awaitingRemoval.has(element)) {
      awaitingRemoval.set(element, new Set());
    }
    awaitingRemoval.get(element).add(release);
    if (!removalObserver) {
      removalObserver = new MutationObserver(releaseRemoved);
      removalObserver.observe(document, { childList: true, subtree: true });
    }
  }

  /**
   * Attaches and releases States dependencies.
   */
  Drupal.behaviors.states = {
    attach(context) {
      once('states', '[data-drupal-states]', context).forEach((element) => {
        const config = JSON.parse(element.getAttribute('data-drupal-states'));
        const dependents = Object.entries(config || {}).map(
          ([state, constraints]) =>
            new states.Dependent({
              elements: [element],
              state: states.State.sanitize(state),
              constraints,
              defer: true,
            }),
        );
        attached.set(element, dependents);
        dependents.forEach((dependent) => dependent.connect());
      });
      // Retry only previously unresolved legacy-selector dependents that are
      // still connected to the document.
      Array.from(pending).forEach((dependent) => {
        if (!dependent.elements?.some((element) => element.isConnected)) {
          pending.delete(dependent);
          return;
        }
        dependent.connect();
      });
      while (states.postponed.length) {
        states.postponed.shift()();
      }
    },

    detach(context, settings, trigger) {
      if (trigger !== 'unload') {
        return;
      }
      const elements = once.remove('states', '[data-drupal-states]', context);
      if (
        context instanceof Element &&
        context.matches('[data-drupal-states]')
      ) {
        elements.push(...once.remove('states', context));
      }
      elements.forEach((element) => {
        attached.get(element)?.forEach((dependent) => {
          pending.delete(dependent);
          dependent.disconnect();
        });
        attached.delete(element);
      });
      unloadTargetsInContext(context);
    },
  };

  /**
   * Object representing an element that depends on other elements.
   * @class Drupal.states.Dependent
   * @param {object} args
   *   Object with the following keys (all of which are required)
   * @param {Element[]} args.elements
   *   The dependent elements.
   * @param {Drupal.states.State} args.state
   *   A State object describing the state that is dependent
   * @param {object} args.constraints
   *   An object with dependency specifications. Lists all elements that this
   *   element depends on. It can be nested and can contain
   *   arbitrary AND and OR clauses.
   */
  states.Dependent = function (args) {
    Object.assign(this, args);
    this.values = Object.create(null);
    this.oldValue = null;
    this.disposers = new Set();
    this.dependees = this.getDependees();
    if (!args.defer) {
      this.connect();
    }
  };

  /**
   * Comparison functions for comparing the value of an element with the
   * specification from the dependency settings. If the object type can't be
   * found in this list, the === operator is used by default.
   * @name Drupal.states.Dependent.comparisons
   */
  states.Dependent.comparisons = {
    RegExp(reference, value) {
      return reference.test(value);
    },
    Function(reference, value) {
      // The "reference" variable is a comparison function.
      return reference(value);
    },
    Array(reference, value) {
      // Make sure value is an array.
      if (!Array.isArray(value)) {
        return false;
      }

      // The arrays values should match.
      return JSON.stringify(reference.sort()) === JSON.stringify(value.sort());
    },
    Number(reference, value) {
      // If "reference" is a number and "value" is a string, then cast
      // reference as a string before applying the strict comparison in
      // compare().
      // Otherwise numeric keys in the form's #states array fail to match
      // string values returned by form controls.
      return typeof value === 'string'
        ? compare(reference.toString(), value)
        : compare(reference, value);
    },
  };

  states.Dependent.prototype = {
    /**
     * Connects resolved dependees and retries unresolved legacy selectors.
     */
    connect() {
      if (this.connected) {
        return;
      }
      const matches = new Map();
      for (const selector of Object.keys(this.dependees)) {
        const elements = select(selector);
        if (elements === null) {
          if (this.elements?.some((element) => element.isConnected)) {
            pending.add(this);
          }
          return;
        }
        matches.set(selector, elements);
      }
      pending.delete(this);
      this.connected = true;
      matches.forEach((elements, selector) => {
        this.initializeDependee(selector, this.dependees[selector], elements);
      });
    },

    /**
     * Releases the listeners and shared trigger subscriptions.
     */
    disconnect() {
      pending.delete(this);
      const disposers = Array.from(this.disposers);
      this.disposers.clear();
      disposers.forEach((dispose) => dispose());
      this.connected = false;
    },

    /**
     * Watches each unique selector and state combination.
     * @param {string} selector
     *   The selector describing the dependee.
     * @param {string[]} dependeeStates
     *   The states this dependent reads.
     * @param {Element[]} [elements]
     *   The resolved dependee elements.
     */
    initializeDependee(selector, dependeeStates, elements) {
      const resolved = elements ?? select(selector) ?? [];
      this.values[selector] = {};
      for (const stateName of new Set(dependeeStates)) {
        const state = states.State.sanitize(stateName);
        this.values[selector][state.name] = null;

        for (const element of resolved) {
          let untrack;
          const removeListener = listen(
            element,
            `state:${state}`,
            (event) => this.update(selector, state, event.detail?.value),
            { selector, state },
          );
          const dispose = () => {
            this.disposers.delete(dispose);
            untrack();
            removeListener();
          };
          untrack = trackTargetCleanup(element, dispose);
          this.disposers.add(dispose);
        }

        const releaseTrigger = acquireTrigger(resolved, selector, state);
        const disposeTrigger = () => {
          this.disposers.delete(disposeTrigger);
          releaseTrigger();
        };
        this.disposers.add(disposeTrigger);
      }
    },

    /**
     * Compares a value with a reference value.
     * @memberof Drupal.states.Dependent#
     * @param {object} reference
     *   The value used for reference.
     * @param {string} selector
     *   CSS selector describing the dependee.
     * @param {Drupal.states.State} state
     *   A State object describing the dependee's updated state.
     * @return {boolean}
     *   true or false.
     */
    compare(reference, selector, state) {
      const value = this.values[selector][state.name];
      if (reference.constructor.name in states.Dependent.comparisons) {
        // Use a custom compare function for certain reference value types.
        return states.Dependent.comparisons[reference.constructor.name](
          reference,
          value,
        );
      }

      // Do a plain comparison otherwise.
      return compare(reference, value);
    },

    /**
     * Update the value of a dependee's state.
     * @memberof Drupal.states.Dependent#
     * @param {string} selector
     *   CSS selector describing the dependee.
     * @param {Drupal.states.state} state
     *   A State object describing the dependee's updated state.
     * @param {string} value
     *   The new value for the dependee's updated state.
     */
    update(selector, state, value) {
      // Only act when the 'new' value is actually new.
      if (value !== this.values[selector][state.name]) {
        this.values[selector][state.name] = value;
        this.reevaluate();
      }
    },

    /**
     * Triggers change events in case a state changed.
     * @memberof Drupal.states.Dependent#
     */
    reevaluate() {
      // Check whether any constraint for this dependent state is satisfied.
      let value = this.verifyConstraints(this.constraints);

      // Only invoke a state change event when the value actually changed.
      if (value !== this.oldValue) {
        // Store the new value so that we can compare later whether the value
        // actually changed.
        this.oldValue = value;

        // Normalize the value to match the normalized state name.
        value = invert(value, this.state.invert);

        // By adding "trigger: true", we ensure that state changes don't go into
        // infinite loops.
        publishState(this.elements, `state:${this.state}`, {
          value,
          trigger: true,
        });
      }
    },

    /**
     * Evaluates child constraints to determine if a constraint is satisfied.
     * @memberof Drupal.states.Dependent#
     * @param {object|Array} constraints
     *   A constraint object or an array of constraints.
     * @param {string} selector
     *   The selector for these constraints. If undefined, there isn't yet a
     *   selector that these constraints apply to. In that case, the keys of the
     *   object are interpreted as the selector if encountered.
     * @return {boolean}
     *   true or false, depending on whether these constraints are satisfied.
     */
    verifyConstraints(constraints, selector) {
      let result;
      if (Array.isArray(constraints)) {
        // This constraint is an array (OR or XOR).
        const isOr = !constraints.includes('xor');
        const len = constraints.length;
        for (let i = 0; i < len; i++) {
          if (constraints[i] !== 'xor') {
            const constraint = this.checkConstraints(
              constraints[i],
              selector,
              i,
            );
            // Return if this is OR and we have a satisfied constraint or if
            // this is XOR and we have a second satisfied constraint.
            if (constraint && (isOr || result)) {
              return isOr;
            }
            result = result || constraint;
          }
        }
      }
      // Make sure we don't try to iterate over things other than objects. This
      // shouldn't normally occur, but in case the condition definition is
      // bogus, we don't want to end up with an infinite loop.
      else if (constraints && typeof constraints === 'object') {
        const prototype = Object.getPrototypeOf(constraints);
        if (prototype === Object.prototype || prototype === null) {
          // This constraint is an object (AND).
          for (const [key, value] of Object.entries(constraints)) {
            result = ternary(
              result,
              this.checkConstraints(value, selector, key),
            );
            if (result === false) {
              return false;
            }
          }
        }
      }
      return result;
    },

    /**
     * Checks whether the value matches the requirements for this constraint.
     * @memberof Drupal.states.Dependent#
     * @param {string|Array|object} value
     *   Either the value of a state or an array/object of constraints. In the
     *   latter case, resolving the constraint continues.
     * @param {string} [selector]
     *   The selector for this constraint. If undefined, there isn't yet a
     *   selector that this constraint applies to. In that case, the state key
     *   is propagates to a selector and resolving continues.
     * @param {Drupal.states.State} [state]
     *   The state to check for this constraint. If undefined, resolving
     *   continues. If both selector and state aren't undefined and valid
     *   non-numeric strings, a lookup for the actual value of that selector's
     *   state is performed. This parameter is not a State object but a pristine
     *   state string.
     * @return {boolean}
     *   true or false, depending on whether this constraint is satisfied.
     */
    checkConstraints(value, selector, state) {
      // Normalize the last parameter. If it's non-numeric, we treat it either
      // as a selector (in case there isn't one yet) or as a trigger/state.
      if (typeof state !== 'string' || /[0-9]/.test(state[0])) {
        state = null;
      } else if (typeof selector === 'undefined') {
        // Propagate the state to the selector when there isn't one yet.
        selector = state;
        state = null;
      }

      if (state !== null) {
        // Constraints is the actual constraints of an element to check for.
        state = states.State.sanitize(state);
        return invert(this.compare(value, selector, state), state.invert);
      }

      // Resolve this constraint as an AND/OR operator.
      return this.verifyConstraints(value, selector);
    },

    /**
     * Gathers information about all required triggers.
     * @memberof Drupal.states.Dependent#
     * @return {object}
     *   An object describing the required triggers.
     */
    getDependees() {
      const cache = Object.create(null);
      // Swivel the lookup function so that we can record all available
      // selector- state combinations for initialization.
      const _compare = this.compare;
      this.compare = function (reference, selector, state) {
        (cache[selector] || (cache[selector] = [])).push(state.name);
        // Return nothing (=== undefined) so that the constraint loops are not
        // broken.
      };

      // This call doesn't actually verify anything but uses the resolving
      // mechanism to go through the constraints array, trying to look up each
      // value. Since we swivelled the compare function, this comparison returns
      // undefined and lookup continues until the very end. Instead of lookup up
      // the value, we record that combination of selector and state so that we
      // can initialize all triggers.
      this.verifyConstraints(this.constraints);
      // Restore the original function.
      this.compare = _compare;

      return cache;
    },
  };

  const elementTriggers = new WeakMap();

  /**
   * Returns the active trigger driving a state on an element.
   * @param {Element} element
   *   The trigger element.
   * @param {string} stateName
   *   The normalized state name.
   * @return {Drupal.states.Trigger|undefined}
   *   The active trigger, if any.
   */
  function getElementTrigger(element, stateName) {
    const list = elementTriggers.get(element)?.get(stateName);
    return list?.at(-1);
  }

  /**
   * Marks every element in a trigger's scope as driving that state.
   * @param {Element[]} elements
   *   The trigger's elements.
   * @param {string} stateName
   *   The normalized state name.
   * @param {Drupal.states.Trigger} trigger
   *   The active trigger instance.
   */
  function setElementTrigger(elements, stateName, trigger) {
    for (const element of elements) {
      let map = elementTriggers.get(element);
      if (!map) {
        map = new Map();
        elementTriggers.set(element, map);
      }
      let list = map.get(stateName);
      if (!list) {
        list = [];
        map.set(stateName, list);
      }
      if (!list.includes(trigger)) {
        list.push(trigger);
      }
    }
  }

  /**
   * Clears a trigger's per-element markers when it disconnects.
   * @param {Element[]} elements
   *   The trigger's elements.
   * @param {string} stateName
   *   The normalized state name.
   * @param {Drupal.states.Trigger} trigger
   *   The disconnecting trigger instance.
   */
  function clearElementTrigger(elements, stateName, trigger) {
    for (const element of elements) {
      const map = elementTriggers.get(element);
      const list = map?.get(stateName);
      if (list) {
        const index = list.indexOf(trigger);
        if (index !== -1) {
          list.splice(index, 1);
        }
        if (!list.length) {
          map.delete(stateName);
        }
      }
    }
  }

  /**
   * Shares a trigger among dependents using pinned per-element deduplication.
   * @param {Element[]} elements
   *   The trigger's ordered scope.
   * @param {string} selector
   *   The selector describing the scope.
   * @param {Drupal.states.State} state
   *   The monitored state.
   * @return {function(): void}
   *   Releases this dependency's use of the trigger.
   */
  function acquireTrigger(elements, selector, state) {
    if (!elements.length) {
      return () => {};
    }
    const existing = getElementTrigger(elements[0], state.name);
    if (existing) {
      if (typeof existing.users === 'number') {
        existing.users += 1;
        return () => {
          existing.users -= 1;
          if (!existing.users) {
            existing.disconnect();
          }
        };
      }
      return () => {};
    }
    const trigger = new states.Trigger({ selector, state, elements });
    if (getElementTrigger(elements[0], state.name) !== trigger) {
      return () => {};
    }
    trigger.users = 1;
    return () => {
      trigger.users -= 1;
      if (!trigger.users) {
        trigger.disconnect();
      }
    };
  }

  const registeredTriggers = new Map();

  /**
   * Registers native trigger callbacks for a state.
   *
   * An initializer receives the array of matching DOM elements. A map of
   * value callbacks uses that array as its receiver, with a native event
   * when reevaluating after a change. The native registration takes precedence
   * over assignments to the deprecated collection callback surface.
   * @param {string} name
   *   The normalized state name.
   * @param {function(Element[]): (function()|undefined)|object} trigger
   *   An initializer, or a map of event names to value callbacks.
   */
  states.registerTrigger = function (name, trigger) {
    registeredTriggers.set(name, trigger);
  };

  /**
   * @class Drupal.states.Trigger
   * @param {object} args
   *   Trigger arguments. If omitted, elements are selected from args.selector.
   */
  states.Trigger = function (args) {
    Object.assign(this, args);
    const name = this.state?.name ?? String(this.state);
    if (
      registeredTriggers.has(name) ||
      Object.hasOwn(states.Trigger.states, name)
    ) {
      this.elements = this.elements ?? select(this.selector) ?? [];
      this.disposers = new Set();
      this.active = true;
      if (this.elements.length && !getElementTrigger(this.elements[0], name)) {
        this.initialize();
      }
    }
  };

  states.Trigger.prototype = {
    /**
     * @memberof Drupal.states.Trigger#
     */
    initialize() {
      const name = this.state?.name ?? String(this.state);
      const trigger =
        registeredTriggers.get(name) ??
        (states.compatibility.trigger ?? ((key) => states.Trigger.states[key]))(
          name,
        );

      if (typeof trigger === 'function') {
        const dispose = trigger.call(window, this.elements);
        if (typeof dispose === 'function') {
          this.disposers.add(dispose);
        } else {
          this.permanent = true;
        }
        for (const element of this.elements) {
          let untrack;
          const cleanup = () => {
            this.disposers.delete(cleanup);
            untrack();
          };
          const release = () => {
            if (this.permanent && element.isConnected) {
              // A permanent initializer's listeners stay on a connected
              // element, so reattaching must not run it again. Keep it until
              // the unloaded element leaves the document.
              untrack = trackTargetCleanup(element, release);
              releaseOnRemoval(element, release);
              return;
            }
            cleanup();
            this.detachElement(element);
          };
          untrack = trackTargetCleanup(element, release);
          if (!this.permanent) {
            this.disposers.add(cleanup);
          }
        }
      } else {
        Object.entries(trigger || {}).forEach(([event, value]) => {
          this.defaultTrigger(event, value);
        });
      }

      setElementTrigger(this.elements, name, this);
    },

    /**
     * @memberof Drupal.states.Trigger#
     * @param {string} type
     *   The event to monitor.
     * @param {function(Event): unknown} valueFn
     *   The callback reading the current state.
     */
    defaultTrigger(type, valueFn) {
      let oldValue = valueFn.call(this.elements);

      for (const element of this.elements) {
        let untrack;
        const removeListener = listen(element, type, (event) => {
          const value = valueFn.call(this.elements, event);
          if (oldValue !== value) {
            publishState(this.elements, `state:${this.state}`, {
              value,
              oldValue,
            });
            oldValue = value;
          }
        });
        const dispose = () => {
          this.disposers.delete(dispose);
          untrack();
          removeListener();
        };
        untrack = trackTargetCleanup(element, () => {
          dispose();
          this.detachElement(element);
        });
        this.disposers.add(dispose);
      }
      states.postponed.push(() => {
        if (this.active) {
          publishState(this.elements, `state:${this.state}`, {
            value: oldValue,
            oldValue: null,
          });
        }
      });
    },

    /**
     * Detaches a single removed dependee element from this trigger.
     * @param {Element} element
     *   The unloading element.
     */
    detachElement(element) {
      if (!this.elements || !this.elements.includes(element)) {
        return;
      }
      const name = this.state?.name ?? String(this.state);
      clearElementTrigger([element], name, this);
      this.elements = this.elements.filter((item) => item !== element);
      if (!this.elements.length) {
        this.disconnect();
      }
    },

    /**
     * Releases the event listeners when the last dependent unloads.
     */
    disconnect() {
      this.active = false;
      const disposers = Array.from(this.disposers);
      this.disposers.clear();
      disposers.forEach((dispose) => dispose());
      if (!this.permanent && this.elements) {
        const name = this.state?.name ?? String(this.state);
        clearElementTrigger(this.elements, name, this);
        this.elements = [];
      }
    },
  };

  /**
   * Reads a form control's value using the States comparison conventions.
   * @param {Element} [element]
   *   The element whose value to read.
   * @return {unknown}
   *   The control value.
   */
  function controlValue(element) {
    if (element instanceof HTMLSelectElement) {
      const values = Array.from(element.selectedOptions)
        .filter(
          (option) => !option.disabled && !option.closest('optgroup[disabled]'),
        )
        .map((option) => option.value);
      return element.multiple ? values : (values[0] ?? null);
    }
    return element ? (element.value ?? '') : undefined;
  }

  /**
   * Tests whether the first matched control is empty.
   * @return {boolean}
   *   Whether its value is an empty string.
   */
  function empty() {
    return controlValue(this[0]) === '';
  }

  /**
   * Tests whether any matched control is checked.
   * @return {boolean}
   *   Whether any control is checked.
   */
  function checked() {
    for (let i = 0; i < this.length; i++) {
      if (this[i].checked) {
        return true;
      }
    }
    return false;
  }

  /**
   * Reads the selected member of a group or the value of a single control.
   * @return {unknown}
   *   The current control value.
   */
  function value() {
    if (this.length > 1) {
      for (let i = 0; i < this.length; i++) {
        if (this[i].matches(':checked')) {
          return controlValue(this[i]) || false;
        }
      }
      return false;
    }
    return controlValue(this[0]);
  }

  /**
   * Reads the collapsed state from the event or the details element.
   * @param {Event|object} [event]
   *   The triggering event, if any.
   * @return {boolean}
   *   Whether the element is collapsed.
   */
  function collapsed(event) {
    if (event?.detail && 'value' in event.detail) {
      return event.detail.value;
    }
    if (event && 'value' in event) {
      return event.value;
    }
    return !this[0]?.hasAttribute('open');
  }

  /**
   * Default triggers use native element arrays and events.
   * @name Drupal.states.Trigger.states
   */
  states.Trigger.states = {
    empty: {
      keyup: empty,
      // Listen to change for number controls with native spinners.
      change: empty,
    },
    checked: {
      change: checked,
    },
    value: {
      keyup: value,
      change: value,
    },
    collapsed: {
      collapsed,
    },
  };

  // The bridge uses these identities to distinguish defaults from legacy
  // assignments, including assignments made before the bridge attaches.
  states.compatibility.defaults = Object.values(states.Trigger.states).flatMap(
    (trigger) => Object.values(trigger),
  );

  /**
   * A state object is used for describing the state and performing aliasing.
   * @class Drupal.states.State
   * @param {string} state
   *   The name of the state.
   */
  states.State = function (state) {
    /**
     * Original unresolved name.
     */
    this.pristine = state;
    this.name = state;

    // Normalize the state name.
    let process = true;
    do {
      // Iteratively remove exclamation marks and invert the value.
      while (this.name.charAt(0) === '!') {
        this.name = this.name.substring(1);
        this.invert = !this.invert;
      }

      // Replace the state with its normalized name.
      if (Object.hasOwn(states.State.aliases, this.name)) {
        this.name = states.State.aliases[this.name];
      } else {
        process = false;
      }
    } while (process);
  };

  /**
   * Creates a new State object by sanitizing the passed value.
   * @name Drupal.states.State.sanitize
   * @param {string|Drupal.states.State} state
   *   A state object or the name of a state.
   * @return {Drupal.states.state}
   *   A state object.
   */
  states.State.sanitize = function (state) {
    if (state instanceof states.State) {
      return state;
    }

    return new states.State(state);
  };

  /**
   * This list of aliases is used to normalize states and associates negated
   * names with their respective inverse state.
   * @name Drupal.states.State.aliases
   */
  states.State.aliases = {
    enabled: '!disabled',
    invisible: '!visible',
    invalid: '!valid',
    untouched: '!touched',
    optional: '!required',
    filled: '!empty',
    unchecked: '!checked',
    irrelevant: '!relevant',
    expanded: '!collapsed',
    open: '!collapsed',
    closed: 'collapsed',
    readwrite: '!readonly',
  };

  states.State.prototype = {
    /**
     * @memberof Drupal.states.State#
     */
    invert: false,

    /**
     * Ensures that just using the state object returns the name.
     * @memberof Drupal.states.State#
     * @return {string}
     *   The name of the state.
     */
    toString() {
      return this.name;
    },
  };

  /**
   * Global state change handlers. These are bound to "document" to cover all
   * elements whose state changes. Events sent to elements within the page
   * bubble up to these handlers. We use this system so that themes and modules
   * can override these state change handlers for particular parts of a page.
   */

  const wrapper = '.js-form-item, .js-form-submit, .js-form-wrapper';
  const item = '.js-form-item, .js-form-wrapper';
  const supportsDisabled =
    'button, fieldset, optgroup, option, select, textarea, input';
  const displays = new WeakMap();

  /**
   * Applies visibility without losing the element's inline display value.
   * @param {HTMLElement} element
   *   The element to change.
   * @param {boolean} show
   *   Whether the element should be visible.
   */
  function visible(element, show) {
    const { style } = element;
    if (!show) {
      if (style.display !== 'none') {
        displays.set(element, style.display);
        style.display = 'none';
      }
      return;
    }
    if (style.display === 'none') {
      style.display = displays.get(element) ?? '';
    }
    if (style.display === '' && getComputedStyle(element).display === 'none') {
      // Match the default display for elements initially hidden by a rule.
      const sample = document.body.appendChild(
        document.createElement(element.localName),
      );
      const display = getComputedStyle(sample).display;
      sample.remove();
      style.display = display === 'none' ? 'block' : display;
    }
  }

  // Events that stand in for a pinned jQuery .trigger() call. The bridge
  // delivers them to jQuery handlers as one synthetic event.
  const syntheticTriggers = new WeakSet();
  states.compatibility.isSyntheticTrigger = (event) =>
    syntheticTriggers.has(event);

  const handlers = {
    disabled(target, value) {
      const container = target.closest(wrapper);
      if (container) {
        if (value === undefined) {
          container.classList.toggle('form-disabled');
        } else {
          container.classList.toggle('form-disabled', Boolean(value));
          container.querySelectorAll(supportsDisabled).forEach((element) => {
            element.disabled = Boolean(value);
          });
          if (container.matches(supportsDisabled)) {
            container.disabled = Boolean(value);
          }
        }
      }
    },

    readonly(target, value) {
      const container = target.closest(wrapper);
      if (container) {
        if (value === undefined) {
          container.classList.toggle('form-readonly');
        } else {
          container.classList.toggle('form-readonly', Boolean(value));
          container.querySelectorAll('input, textarea').forEach((element) => {
            element.readOnly = Boolean(value);
          });
        }
      }
    },

    required(target, value) {
      if (value) {
        target.setAttribute('required', 'required');
        const selector = target.id
          ? `label[for="${CSS.escape(target.id)}"]`
          : 'label';
        target
          .closest(item)
          ?.querySelectorAll(selector)
          .forEach((label) => {
            label.classList.add('js-form-required', 'form-required');
          });
      } else {
        target.removeAttribute('required');
        target
          .closest(item)
          ?.querySelectorAll('label.js-form-required')
          .forEach((label) => {
            label.classList.remove('js-form-required', 'form-required');
          });
      }
    },

    visible(target, value) {
      const element = target.tagName === 'A' ? target : target.closest(wrapper);
      if (element) {
        visible(
          element,
          typeof value === 'boolean'
            ? value
            : getComputedStyle(element).display === 'none',
        );
      }
    },

    checked(target, value) {
      const inputs = target.closest(item)?.querySelectorAll('input');
      if (value !== undefined) {
        inputs?.forEach((input) => {
          input.checked = value;
        });
      }
      inputs?.forEach((input) => {
        const event = new Event('change', { bubbles: true, cancelable: true });
        syntheticTriggers.add(event);
        publish(input, event);
      });
    },

    collapsed(target, value) {
      const summary = target.querySelector(':scope > summary');
      if (summary && target.hasAttribute('open') === value) {
        // A dispatched click toggles the details element like click() does.
        // Publishing it lets the bridge give jQuery a synthetic click.
        const event = new MouseEvent('click', {
          bubbles: true,
          cancelable: true,
          view: window,
        });
        syntheticTriggers.add(event);
        publish(summary, event);
      }
    },
  };

  Object.entries(handlers).forEach(([state, handler]) => {
    listen(document, `state:${state}`, (event) => {
      if (event.detail?.trigger) {
        handler(event.target, event.detail.value);
      }
    });
  });
  document.dispatchEvent(new Event('drupal:states-ready'));
})(Drupal, once);
