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
   * Preserves cancellation in the legacy queue while core uses a native event.
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
      listener(native, matched);
      if (native.defaultPrevented) {
        event.preventDefault();
      }
      if (native.cancelBubble && !event.isPropagationStopped()) {
        event.stopPropagation();
      }
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
        const { detailsAria, tabbingFocusTrap, navTabs, formInteraction } =
          binding;
        if (!detailsAria && !tabbingFocusTrap && !navTabs && !formInteraction) {
          return;
        }
        const contract = detailsAria
          ? 'details summary clicks'
          : navTabs
            ? 'navigation tabs'
            : tabbingFocusTrap
              ? 'tabbing focus traps'
              : type;
        observeRemoval(type);
        const special = jquery.event.special[type];
        const mapped = selector ? special?.delegateType : special?.bindType;
        if (mapped && mapped !== type) {
          observeRemoval(mapped);
        }
        const element = jquery(target);
        const name = namespace ? `${type}.${namespace}` : type;
        const deliver = function (event, fragmentTarget) {
          if (event.isTrigger) {
            warn(
              `The jQuery synthetic trigger for ${contract}`,
              `Use the native ${type} event.`,
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
                    : undefined,
              });
              Object.defineProperties(native, {
                target: { value: event.target },
                key: { value: event.key },
                shiftKey: { value: event.shiftKey },
                keyCode: { value: event.keyCode },
                button: { value: event.button },
              });
              if (event.isDefaultPrevented()) {
                native.preventDefault();
              }
            }
            deliverFormInteraction(listener, native, event, this);
          } else if (type === 'click') {
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

  const fragmentEvent = 'formFragmentLinkClickOrHashChange';
  const tabbingMigration = 'Use native context members and element lists.';
  const tabbingEvents = [
    'drupalTabbingConstrained',
    'drupalTabbingContextActivated',
    'drupalTabbingContextDeactivated',
    'drupalTabbingContextReleased',
  ];

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
   * Supplies the positional arguments of existing form and tabbing events.
   */
  function installPublications() {
    const publications = new WeakMap();
    const legacyTypes = [...tabbingEvents, fragmentEvent];
    window.Drupal.eventBindings.observePublications((event) => {
      if (legacyTypes.includes(event.type)) {
        publications.set(event, [
          event.type === fragmentEvent ? jquery(event.detail) : event.detail,
        ]);
        return () => publications.delete(event);
      }
      return undefined;
    });
    legacyTypes.forEach((type) => {
      const special =
        jquery.event.special[type] || (jquery.event.special[type] = {});
      const previous = special.handle;
      special.handle = function (event, ...args) {
        const positional =
          !event.isTrigger && publications.get(event.originalEvent);
        return (previous || event.handleObj.handler).call(
          this,
          event,
          ...(positional || args),
        );
      };
    });
  }

  /**
   * Installs the legacy summary plugins on an explicitly attached instance.
   */
  function installSummaryMethods() {
    const { form } = window.Drupal;
    jquery.fn.drupalGetSummary = function () {
      warn(
        'jQuery.fn.drupalGetSummary',
        'Use Drupal.form.getSummary(element).',
      );
      return form.getSummary(this[0]);
    };
    jquery.fn.drupalSetSummary = function (summary) {
      warn(
        'jQuery.fn.drupalSetSummary',
        'Use Drupal.form.setSummary(elements, summary).',
      );
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
            return callback.call(
              this,
              legacyEvents.get(event) ||
                (event instanceof jquery.Event ? event : jquery.Event(event)),
            );
          };
          adapted.legacyCallback = callback;
          this[nativeName] = adapted;
        },
      });
    });

    Drupal.DropButton = function (element, options) {
      if (element?.nodeType !== Node.ELEMENT_NODE) {
        warn('The jQuery input to Drupal.DropButton', migration);
        [element] = jquery(element);
      }
      return Reflect.construct(
        NativeDropButton,
        [element, options],
        new.target || NativeDropButton,
      );
    };
    Object.setPrototypeOf(Drupal.DropButton, NativeDropButton);
    Drupal.DropButton.prototype = prototype;
  }

  /**
   * Keeps legacy tab collections, constructor inputs and theme overrides.
   */
  function installVerticalTabs() {
    const { Drupal } = window;
    const NativeTab = Drupal.verticalTab;
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

    Drupal.verticalTab = function (settings) {
      if (!settings.detailsElement) {
        const { details, ...remaining } = settings;
        warn('The jQuery input to Drupal.verticalTab', migration);
        settings = { ...remaining, detailsElement: jquery(details)[0] };
      }
      return Reflect.construct(NativeTab, [settings], new.target || NativeTab);
    };
    Drupal.verticalTab.prototype = NativeTab.prototype;

    document.addEventListener('drupal:vertical-tab-created', (event) => {
      jquery(event.target).data('verticalTab', event.detail);
    });
    document.querySelectorAll('.vertical-tabs__pane').forEach((pane) => {
      const tab = Drupal.verticalTabs.get(pane);
      if (tab) {
        jquery(pane).data('verticalTab', tab);
      }
    });

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
    installPublications();
  } else {
    document.addEventListener(
      'drupal:event-bindings-ready',
      () => {
        install();
        installPublications();
      },
      { once: true },
    );
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
