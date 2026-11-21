/**
 * @file
 * Manages page tabbing modifications made by modules.
 */

/**
 * Allow modules to respond to the constrain event.
 *
 * @event drupalTabbingConstrained
 */

/**
 * Allow modules to respond to the tabbingContext release event.
 *
 * @event drupalTabbingContextReleased
 */

/**
 * Allow modules to respond to the constrain event.
 *
 * @event drupalTabbingContextActivated
 */

/**
 * Allow modules to respond to the constrain event.
 *
 * @event drupalTabbingContextDeactivated
 */

(function (Drupal, { tabbable, isTabbable }) {
  /**
   * Provides an API for managing page tabbing order modifications.
   *
   * @constructor Drupal~TabbingManager
   */
  function TabbingManager() {
    /**
     * Tabbing sets are stored as a stack. The active set is at the top of the
     * stack. We use a JavaScript array as if it were a stack; we consider the
     * first element to be the bottom and the last element to be the top. This
     * allows us to use JavaScript's built-in Array.push() and Array.pop()
     * methods.
     *
     * @type {Array.<Drupal~TabbingContext>}
     */
    this.stack = [];
    this.originalTabIndices = new WeakMap();
    this.focusTraps = new WeakMap();
  }

  /**
   * Stores a set of tabbable elements.
   *
   * This constraint can be removed with the release() method.
   *
   * @constructor Drupal~TabbingContext
   *
   * @param {object} options
   *   A set of initiating values
   * @param {number} options.level
   *   The level in the TabbingManager's stack of this tabbingContext.
   * @param {HTMLElement[]} options.tabbableElements
   *   The DOM elements that should be reachable via the tab key when this
   *   tabbingContext is active.
   * @param {HTMLElement[]} options.disabledElements
   *   The DOM elements that should not be reachable via the tab key when this
   *   tabbingContext is active.
   * @param {boolean} options.released
   *   A released tabbingContext can never be activated again. It will be
   *   cleaned up when the TabbingManager unwinds its stack.
   * @param {boolean} options.active
   *   When true, the tabbable elements of this tabbingContext will be reachable
   *   via the tab key and the disabled elements will not. Only one
   *   tabbingContext can be active at a time.
   *  @param {boolean} options.trapFocus
   *   When true, focus is trapped within the tabbable elements, i.e. focus will
   *   remain within the browser.
   */
  function TabbingContext(options) {
    Object.assign(
      this,
      /** @lends Drupal~TabbingContext# */ {
        /**
         * @type {?number}
         */
        level: null,

        /**
         * @type {HTMLElement[]}
         */
        tabbableElements: [],

        /**
         * @type {HTMLElement[]}
         */
        disabledElements: [],

        /**
         * @type {boolean}
         */
        released: false,

        /**
         * @type {boolean}
         */
        active: false,

        /**
         * @type {boolean}
         */
        trapFocus: false,
      },
      options,
    );
  }

  /**
   * Add public methods to the TabbingManager class.
   */
  Object.assign(
    TabbingManager.prototype,
    /** @lends Drupal~TabbingManager# */ {
      /**
       * Constrain tabbing to the specified set of elements only.
       *
       * Makes elements outside of the specified set of elements unreachable via
       * the tab key.
       *
       * @param {string|Element|Iterable<Element>} elements
       *   The selector, element or list of elements to constrain tabbing to.
       * @param {object} [options={}]
       *   Constrain options.
       * @param {boolean} [options.trapFocus=false]
       *   When true, tabbing is trapped within the set of elements and can't
       *   leave the browser. If the final element in the set is tabbed, the
       *   first element in the set will receive focus. If the first element in
       *   the set is shift-tabbed, the last element in the set will receive
       *   focus.
       *   When false, it is possible to tab out of the browser window by
       *   tabbing the final element in the set or shift-tabbing the first
       *   element in the set.
       *
       * @return {Drupal~TabbingContext}
       *   The TabbingContext instance.
       *
       * @fires event:drupalTabbingConstrained
       */
      constrain(elements, options) {
        const roots =
          typeof elements === 'string' && elements
            ? document.querySelectorAll(elements)
            : elements;
        return this.constrainElements(roots, options);
      },

      /**
       * Constrains tabbing to native elements, including on mixed pages.
       *
       * @param {Element|Iterable<Element>} elements
       *   The element or list of elements to constrain tabbing to.
       * @param {object} [options={}]
       *   The constraint options.
       * @param {boolean} [options.trapFocus=false]
       *   Whether Tab and Shift+Tab wrap within the set.
       * @return {Drupal~TabbingContext}
       *   The active context.
       */
      constrainElements(elements, { trapFocus = false } = {}) {
        // Deactivate all tabbingContexts to prepare for the new constraint. A
        // tabbingContext instance will only be reactivated if the stack is
        // unwound to it in the _unwindStack() method.
        const il = this.stack.length;
        for (let i = 0; i < il; i++) {
          this.stack[i].deactivate();
        }

        // The "active tabbing set" are the elements tabbing should be constrained
        // to.
        const roots =
          elements instanceof Element ||
          elements instanceof Document ||
          elements instanceof DocumentFragment
            ? [elements]
            : elements;
        const tabbableElements = Array.from(roots || []).flatMap((root) => {
          const target = root instanceof Document ? root.documentElement : root;
          if (!target || typeof target.querySelectorAll !== 'function') {
            return [];
          }
          const descendants = tabbable(target);
          return target instanceof Element && isTabbable(target)
            ? [...descendants, target]
            : descendants;
        });

        const tabbingContext = new TabbingContext({
          // The level is the current height of the stack before this new
          // tabbingContext is pushed on top of the stack.
          level: this.stack.length,
          tabbableElements,
          trapFocus,
        });

        this.stack.push(tabbingContext);

        // Activates the tabbingContext; this will manipulate the DOM to constrain
        // tabbing.
        tabbingContext.activate();

        // Allow modules to respond to the constrain event.
        publish('drupalTabbingConstrained', tabbingContext);

        return tabbingContext;
      },

      /**
       * Restores a former tabbingContext when an active one is released.
       *
       * The TabbingManager stack of tabbingContext instances will be unwound
       * from the top-most released tabbingContext down to the first non-released
       * tabbingContext instance. This non-released instance is then activated.
       */
      release() {
        // Unwind as far as possible: find the topmost non-released
        // tabbingContext.
        let toActivate = this.stack.length - 1;
        while (toActivate >= 0 && this.stack[toActivate].released) {
          toActivate--;
        }

        // Delete all tabbingContexts after the to be activated one. They have
        // already been deactivated, so their effect on the DOM has been reversed.
        this.stack.splice(toActivate + 1);

        // Get topmost tabbingContext, if one exists, and activate it.
        if (toActivate >= 0) {
          this.stack[toActivate].activate();
        }
      },

      /**
       * Makes all elements outside of the tabbingContext's set untabbable.
       *
       * Elements made untabbable have their original tabindex and autofocus
       * values stored so that they might be restored later when this
       * tabbingContext is deactivated.
       *
       * @param {Drupal~TabbingContext} tabbingContext
       *   The TabbingContext instance that has been activated.
       */
      activate(tabbingContext) {
        const { level, tabbableElements } = tabbingContext;
        const allowed = new Set(tabbableElements);
        const disabledElements = tabbable(document.body).filter(
          (element) => !allowed.has(element),
        );
        tabbingContext.disabledElements = disabledElements;
        disabledElements.forEach((element) => {
          this.recordTabindexElement(element, level);
        });
        disabledElements.forEach((element) => {
          element.tabIndex = -1;
          element.autofocus = false;
        });

        // Prefer the last autofocus element, then the first tabbable element.
        const first = tabbableElements[0];
        const last = tabbableElements.at(-1);
        const autofocus = tabbableElements
          .filter((element) => element.hasAttribute('autofocus'))
          .at(-1);
        (autofocus || first)?.focus();

        if (first && tabbingContext.trapFocus) {
          this.focusTraps.set(tabbingContext, [
            focusTrap(last, false, first),
            focusTrap(first, true, last),
          ]);
        }
      },

      /**
       * Restores that tabbable state of a tabbingContext's disabled elements.
       *
       * Elements that were made untabbable have their original tabindex and
       * autofocus values restored.
       *
       * @param {Drupal~TabbingContext} tabbingContext
       *   The TabbingContext instance that has been deactivated.
       */
      deactivate(tabbingContext) {
        this.focusTraps.get(tabbingContext)?.forEach((stop) => stop());
        this.focusTraps.delete(tabbingContext);
        tabbingContext.disabledElements.forEach((element) => {
          this.restoreTabindexElement(element, tabbingContext.level);
        });
      },

      /**
       * Records the tabindex and autofocus attributes of a native element.
       *
       * Override this method directly to register a native implementation.
       *
       * @param {HTMLElement} element
       *   The element being disabled.
       * @param {number} level
       *   The stack level at which the attributes are recorded.
       */
      recordTabindexElement(element, level) {
        let tabInfo = this.originalTabIndices.get(element);
        if (!tabInfo) {
          tabInfo = new Map();
          this.originalTabIndices.set(element, tabInfo);
        }
        tabInfo.set(level, {
          tabindex: element.getAttribute('tabindex'),
          autofocus: element.hasAttribute('autofocus'),
        });
      },

      /**
       * Restores the saved tabindex and autofocus on a native element.
       *
       * Override this method directly to register a native implementation.
       *
       * @param {HTMLElement} element
       *   The element being reactivated.
       * @param {number} level
       *   The stack level from which to restore the attributes.
       */
      restoreTabindexElement(element, level) {
        const tabInfo = this.originalTabIndices.get(element);
        const data = tabInfo?.get(level);
        if (!data) {
          return;
        }
        if (data.tabindex) {
          element.setAttribute('tabindex', data.tabindex);
        } else {
          element.removeAttribute('tabindex');
        }
        if (data.autofocus) {
          element.setAttribute('autofocus', 'autofocus');
        }
        if (level === 0) {
          this.originalTabIndices.delete(element);
        } else {
          let levelToDelete = level;
          while (tabInfo.delete(levelToDelete)) {
            levelToDelete++;
          }
        }
      },
    },
  );

  /**
   * Add public methods to the TabbingContext class.
   */
  Object.assign(
    TabbingContext.prototype,
    /** @lends Drupal~TabbingContext# */ {
      /**
       * Releases this TabbingContext.
       *
       * Once a TabbingContext object is released, it can never be activated
       * again.
       *
       * @fires event:drupalTabbingContextReleased
       */
      release() {
        if (!this.released) {
          this.deactivate();
          this.released = true;
          Drupal.tabbingManager.release(this);
          // Allow modules to respond to the tabbingContext release event.
          publish('drupalTabbingContextReleased', this);
        }
      },

      /**
       * Activates this TabbingContext.
       *
       * @fires event:drupalTabbingContextActivated
       */
      activate() {
        // A released TabbingContext object can never be activated again.
        if (!this.active && !this.released) {
          this.active = true;
          Drupal.tabbingManager.activate(this);
          // Allow modules to respond to the constrain event.
          publish('drupalTabbingContextActivated', this);
        }
      },

      /**
       * Deactivates this TabbingContext.
       *
       * @fires event:drupalTabbingContextDeactivated
       */
      deactivate() {
        if (this.active) {
          this.active = false;
          Drupal.tabbingManager.deactivate(this);
          // Allow modules to respond to the constrain event.
          publish('drupalTabbingContextDeactivated', this);
        }
      },
    },
  );

  /**
   * Publishes a tabbing lifecycle event with its native context.
   *
   * @param {string} type
   *   The lifecycle event name.
   * @param {Drupal~TabbingContext} context
   *   The affected context, supplied as event.detail.
   */
  function publish(type, context) {
    Drupal.eventBindings.publish(
      document,
      new CustomEvent(type, {
        bubbles: true,
        cancelable: true,
        detail: context,
      }),
    );
  }

  /**
   * Wraps focus when Tab is pressed at the edge of the active constraint.
   *
   * @param {HTMLElement} target
   *   The first or last element in the active constraint.
   * @param {boolean} reverse
   *   Whether this endpoint traps Shift+Tab.
   * @param {HTMLElement} next
   *   The other endpoint, which receives focus on wrap.
   * @return {function}
   *   Removes the focus trap.
   */
  function focusTrap(target, reverse, next) {
    const binding = {
      target,
      type: 'keydown',
      namespace: 'focus-trap',
      tabbingFocusTrap: true,
      listener(event) {
        if (event.key === 'Tab' && event.shiftKey === reverse) {
          event.preventDefault();
          next.focus();
        }
      },
      stop() {
        target.removeEventListener('keydown', onKeydown);
        unregister();
      },
    };
    function onKeydown(event) {
      if (!binding.handlesNativeEvent?.(event)) {
        binding.listener(event);
      }
    }
    target.addEventListener('keydown', onKeydown);
    const unregister = Drupal.eventBindings.register(binding);
    return binding.stop;
  }

  // Mark this behavior as processed on the first pass and return if it is
  // already processed.
  if (Drupal.tabbingManager) {
    return;
  }

  /**
   * @type {Drupal~TabbingManager}
   */
  Drupal.tabbingManager = new TabbingManager();
  Object.defineProperty(Drupal.tabbingManager, 'contextPrototype', {
    value: TabbingContext.prototype,
  });
  document.dispatchEvent(new Event('drupal:tabbing-manager-ready'));
})(Drupal, window.tabbable);
