/**
 * @file
 * Drupal's states library.
 * @param {object} Drupal
 *   The Drupal namespace.
 */

(function (Drupal) {
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

  const formPseudos = {
    input: 'input, select, textarea, button',
    radio: 'input[type="radio"]',
    checkbox: 'input[type="checkbox"]',
    text: 'input:not([type]), input[type="text"]',
    password: 'input[type="password"]',
    file: 'input[type="file"]',
    submit:
      'input[type="submit"], button[type="submit"], button:not([type="button"], [type="reset"], [command], [commandfor])',
    button: 'button, input[type="button"]',
    image: 'input[type="image"]',
    reset: 'input[type="reset"], button[type="reset"]',
  };
  const nativePseudos = new Set([
    'checked',
    'disabled',
    'enabled',
    'empty',
    'first-child',
    'last-child',
    'only-child',
    'nth-child',
    'nth-last-child',
    'first-of-type',
    'last-of-type',
    'only-of-type',
    'nth-of-type',
    'nth-last-of-type',
    'is',
    'has',
    'not',
    'scope',
    'where',
  ]);

  /**
   * Normalizes the audited form pseudos in a States selector.
   *
   * Skip escapes, quoted strings and attributes as tokens. Inspect every
   * selector in functional pseudos, including forgiving selector lists.
   * @param {string} selector
   *   A selector from the final States markup.
   * @return {string|null}
   *   A native selector, or null for selectors that need the legacy engine.
   */
  function nativeSelector(selector) {
    const tokens =
      /\\(?:[\da-f]{1,6}[\t\n\r\f ]?|[^\n\r\f])|\[(?:\\.|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|[^\]"'\\])*\]|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|:[\w-]*|\/\*/gis;
    let eligible = true;
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
        // Wrap the substitution so it can follow any simple selector.
        return `:is(${formPseudos[name]})`;
      }
      eligible = eligible && nativePseudos.has(name);
      return token;
    });
    return eligible ? normalized : null;
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
        return Array.from(document.querySelectorAll(normalized));
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
   *   The event name.
   * @param {function(Event): void} listener
   *   The native listener.
   * @param {object} [data]
   *   Binding data used by the legacy adapter.
   * @return {function(): void}
   *   Removes the binding.
   */
  function listen(target, type, listener, data) {
    const binding = { target, type, listener, data, states: true };
    const nativeListener = (event) => {
      if (!binding.handlesNativeEvent?.(event)) {
        return listener(event);
      }
    };
    target.addEventListener(type, nativeListener);
    const unregister = Drupal.eventBindings.register(binding);
    return () => {
      unregister();
      target.removeEventListener(type, nativeListener);
    };
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
      Drupal.eventBindings.publish(
        element,
        new CustomEvent(type, {
          bubbles: true,
          cancelable: true,
          detail: { ...detail },
        }),
      );
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

  const attached = new Map();

  /**
   * Finds state-bearing elements in the context, including the context itself.
   * @param {Document|DocumentFragment|Element} context
   *   The behavior context.
   * @return {Element[]}
   *   The state-bearing elements.
   */
  function stateElements(context) {
    const selector = '[data-drupal-states]';
    const elements = Array.from(context.querySelectorAll(selector));
    if (context.matches?.(selector)) {
      elements.unshift(context);
    }
    return elements;
  }

  /**
   * Compares two ordered sets of matches.
   * @param {Element[]} first
   *   The first set of matches.
   * @param {Element[]} second
   *   The second set of matches.
   * @return {boolean}
   *   Whether both contain the same elements in the same order.
   */
  function sameElements(first, second) {
    return (
      first.length === second.length &&
      first.every((element, index) => element === second[index])
    );
  }

  /**
   * Attaches and releases States dependencies.
   */
  Drupal.behaviors.states = {
    attach(context) {
      stateElements(context).forEach((element) => {
        if (!attached.has(element)) {
          const config = JSON.parse(element.getAttribute('data-drupal-states'));
          attached.set(
            element,
            Object.entries(config || {}).map(
              ([state, constraints]) =>
                new states.Dependent({
                  elements: [element],
                  state: states.State.sanitize(state),
                  constraints,
                  defer: true,
                }),
            ),
          );
        }
      });
      // An insertion or an explicitly attached selector adapter can make a
      // previously unavailable condition resolvable on an existing element.
      attached.forEach((dependents) => {
        dependents.forEach((dependent) => dependent.connect());
      });
      while (states.postponed.length) {
        states.postponed.shift()();
      }
    },

    detach(context, settings, trigger) {
      if (trigger !== 'unload') {
        return;
      }
      stateElements(context).forEach((element) => {
        attached.get(element)?.forEach((dependent) => dependent.disconnect());
        attached.delete(element);
      });
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
    this.disposers = [];
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
      const matches = new Map();
      for (const selector of Object.keys(this.dependees)) {
        const elements = select(selector);
        if (elements === null) {
          return;
        }
        matches.set(selector, elements);
      }
      if (
        this.matches &&
        Array.from(matches).every(([selector, elements]) =>
          sameElements(elements, this.matches.get(selector)),
        )
      ) {
        return;
      }
      this.disconnect();
      this.matches = matches;
      matches.forEach((elements, selector) => {
        this.initializeDependee(selector, this.dependees[selector]);
      });
    },

    /**
     * Releases the listeners and shared trigger subscriptions.
     */
    disconnect() {
      this.disposers.forEach((dispose) => dispose());
      this.disposers.length = 0;
      this.matches = null;
    },

    /**
     * Watches each unique selector and state combination.
     * @param {string} selector
     *   The selector describing the dependee.
     * @param {string[]} dependeeStates
     *   The states this dependent reads.
     */
    initializeDependee(selector, dependeeStates) {
      const elements = this.matches.get(selector);
      this.values[selector] = {};
      for (const stateName of new Set(dependeeStates)) {
        const state = states.State.sanitize(stateName);
        this.values[selector][state.name] = null;

        for (const element of elements) {
          this.disposers.push(
            listen(
              element,
              `state:${state}`,
              (event) => this.update(selector, state, event.detail?.value),
              { selector, state },
            ),
          );
        }

        this.disposers.push(
          acquireTrigger(elements, selector, state, (value) => {
            this.update(selector, state, value);
          }),
        );
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

  const sharedTriggers = new WeakMap();

  /**
   * Shares a trigger among dependents with the same ordered scope and state.
   * @param {Element[]} elements
   *   The trigger's ordered scope.
   * @param {string} selector
   *   The selector describing the scope.
   * @param {Drupal.states.State} state
   *   The monitored state.
   * @param {function(unknown): void} update
   *   Receives the last value when an existing trigger is reused.
   * @return {function(): void}
   *   Releases this dependency's use of the trigger.
   */
  function acquireTrigger(elements, selector, state, update) {
    if (!elements.length) {
      return () => {};
    }
    let triggers = sharedTriggers.get(elements[0]);
    if (!triggers) {
      triggers = new Set();
      sharedTriggers.set(elements[0], triggers);
    }
    let trigger = Array.from(triggers).find(
      (candidate) =>
        candidate.state.name === state.name &&
        sameElements(candidate.elements, elements),
    );
    if (trigger) {
      states.postponed.push(() => {
        if (trigger.initialized) {
          update(trigger.value);
        }
      });
    } else {
      trigger = new states.Trigger({ selector, state, elements });
      triggers.add(trigger);
    }
    trigger.users = (trigger.users || 0) + 1;
    return () => {
      trigger.users -= 1;
      if (!trigger.users) {
        trigger.disconnect();
        triggers.delete(trigger);
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
    this.elements = this.elements ?? select(this.selector) ?? [];
    this.disposers = [];
    this.active = true;
    if (this.elements.length) {
      this.initialize();
    }
  };

  states.Trigger.prototype = {
    /**
     * @memberof Drupal.states.Trigger#
     */
    initialize() {
      const name = this.state.name ?? String(this.state);
      const trigger =
        registeredTriggers.get(name) ??
        (states.compatibility.trigger ?? ((key) => states.Trigger.states[key]))(
          name,
        );

      if (typeof trigger === 'function') {
        const dispose = trigger.call(window, this.elements);
        if (typeof dispose === 'function') {
          this.disposers.push(dispose);
        }
      } else {
        Object.entries(trigger || {}).forEach(([event, value]) => {
          this.defaultTrigger(event, value);
        });
      }
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
        this.disposers.push(
          listen(element, type, (event) => {
            const value = valueFn.call(this.elements, event);
            if (oldValue !== value) {
              this.value = value;
              publishState(this.elements, `state:${this.state}`, {
                value,
                oldValue,
              });
              oldValue = value;
            }
          }),
        );
      }
      states.postponed.push(() => {
        if (this.active) {
          this.initialized = true;
          this.value = oldValue;
          publishState(this.elements, `state:${this.state}`, {
            value: oldValue,
            oldValue: null,
          });
        }
      });
    },

    /**
     * Releases the event listeners when the last dependent unloads.
     */
    disconnect() {
      this.active = false;
      this.disposers.forEach((dispose) => dispose());
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
   * Reads the selected member of a group or the value of a single control.
   * @return {unknown}
   *   The current control value.
   */
  function value() {
    if (this.length > 1) {
      return (
        controlValue(this.find((element) => element.matches(':checked'))) ||
        false
      );
    }
    return controlValue(this[0]);
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
      change() {
        return this.some((element) => element.checked);
      },
    },
    value: {
      keyup: value,
      change: value,
    },
    collapsed: {
      collapsed(event) {
        return event?.detail && 'value' in event.detail
          ? event.detail.value
          : !this[0].hasAttribute('open');
      },
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

  const handlers = {
    disabled(target, value) {
      const container = target.closest(wrapper);
      if (container) {
        container.classList.toggle('form-disabled', Boolean(value));
        container.querySelectorAll(supportsDisabled).forEach((element) => {
          element.disabled = value;
        });
        if (container.matches(supportsDisabled)) {
          container.disabled = value;
        }
      }
    },

    readonly(target, value) {
      const container = target.closest(wrapper);
      if (container) {
        container.classList.toggle('form-readonly', Boolean(value));
        container.querySelectorAll('input, textarea').forEach((element) => {
          element.readOnly = value;
        });
      }
    },

    required(target, value) {
      if (value) {
        target.setAttribute('required', 'required');
      } else {
        target.removeAttribute('required');
      }
      const selector =
        value && target.id ? `label[for="${CSS.escape(target.id)}"]` : 'label';
      target
        .closest(item)
        ?.querySelectorAll(selector)
        .forEach((label) => {
          label.classList.toggle('js-form-required', Boolean(value));
          label.classList.toggle('form-required', Boolean(value));
        });
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
      inputs?.forEach((input) => {
        input.checked = value;
      });
      inputs?.forEach((input) => {
        input.dispatchEvent(
          new Event('change', { bubbles: true, cancelable: true }),
        );
      });
    },

    collapsed(target, value) {
      if (target.hasAttribute('open') === value) {
        target.querySelector(':scope > summary')?.click();
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
})(Drupal);
