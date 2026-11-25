/**
 * @file
 * Attaches behaviors for the Contextual module.
 */

(function (Drupal, drupalSettings, once, storage) {
  const options = Object.assign(drupalSettings.contextual, {
    strings: {
      open: Drupal.t('Open'),
      close: Drupal.t('Close'),
    },
  });
  // Clear the cached contextual links whenever the current user's set of
  // permissions changes.
  const cachedPermissionsHash = storage.getItem(
    'Drupal.contextual.permissionsHash',
  );
  const { permissionsHash } = drupalSettings.user;
  if (cachedPermissionsHash !== permissionsHash) {
    if (typeof permissionsHash === 'string') {
      Object.keys(storage).forEach((key) => {
        if (key.startsWith('Drupal.contextual.')) {
          storage.removeItem(key);
        }
      });
    }
    storage.setItem('Drupal.contextual.permissionsHash', permissionsHash);
  }

  /**
   * Determines if a contextual link is nested & overlapping, if so: adjusts it.
   *
   * This only deals with two levels of nesting; deeper levels are not touched.
   *
   * @param {HTMLElement} contextual
   *   A contextual links placeholder DOM element, containing the actual
   *   contextual links as rendered by the server.
   */
  function adjustIfNestedAndOverlapping(contextual) {
    let outerRegion = contextual.closest('.contextual-region');
    for (
      let parent = outerRegion?.parentElement;
      parent;
      parent = parent.parentElement
    ) {
      if (parent.matches('.contextual-region')) {
        outerRegion = parent;
      }
    }

    const [first, nested] = outerRegion?.querySelectorAll('.contextual') ?? [];
    if (
      !nested ||
      first.getBoundingClientRect().top !== nested.getBoundingClientRect().top
    ) {
      return;
    }

    const trigger = nested.querySelector('.trigger');
    // Temporarily reveal the trigger to measure the nested contextual link.
    trigger.classList.remove('visually-hidden');
    const height = nested.clientHeight;
    trigger.classList.add('visually-hidden');
    nested.style.top = `${nested.offsetTop + height}px`;
  }

  /**
   * Initializes a contextual link: updates its DOM, sets up model and views.
   *
   * @param {HTMLElement} element
   *   A contextual links placeholder DOM element, containing the actual
   *   contextual links as rendered by the server.
   * @param {string} html
   *   The server-side rendered HTML for this contextual link.
   */
  function initContextual(element, html) {
    const region = element.closest('.contextual-region');
    const { contextual } = Drupal;

    element.innerHTML = html;
    element.classList.add('contextual');
    element.insertAdjacentHTML('afterbegin', Drupal.theme('contextualTrigger'));

    // Set the destination parameter on each of the contextual links.
    const destination = `destination=${Drupal.encodePath(
      Drupal.url(drupalSettings.path.currentPath + window.location.search),
    )}`;
    element.querySelectorAll('.contextual-links a').forEach((link) => {
      const url = link.getAttribute('href');
      const glue = url.includes('?') ? '&' : '?';
      link.setAttribute('href', url + glue + destination);
    });
    const title = region?.querySelector('h2')?.textContent.trim() ?? '';
    const model = new Drupal.contextual.ContextualModelView(element, region, {
      ...options,
      title,
    });
    // A listener can remove the link while its model is being initialized.
    if (element.isConnected) {
      contextual.instances.push(model);
      window.dispatchEvent(
        new CustomEvent('contextual-instances-added', { detail: { model } }),
      );
      adjustIfNestedAndOverlapping(element);
    } else {
      model.destroy();
    }
  }

  const pendingPlaceholders = new WeakMap();

  /**
   * Attaches outline behavior for regions associated with contextual links.
   *
   * Events
   *   Contextual triggers an event that can be used by other scripts.
   *   - drupalContextualLinkAdded: Triggered when a contextual link is added.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *  Attaches the outline behavior to the right context.
   */
  Drupal.behaviors.contextual = {
    attach(context) {
      const placeholders = once(
        'contextual-render',
        '[data-contextual-id]',
        context,
      );
      const uncached = new Map();
      placeholders.forEach((element) => {
        const id = element.dataset.contextualId;
        const token = {};
        pendingPlaceholders.set(element, token);
        const render = (html) => {
          if (
            element.isConnected &&
            pendingPlaceholders.get(element) === token
          ) {
            pendingPlaceholders.delete(element);
            if (html) {
              initContextual(element, html);
            }
          }
        };
        const html = storage.getItem(
          `Drupal.contextual.${id}.${options.theme}`,
        );
        if (html?.length) {
          // Let the other behaviors attach before notifying their listeners.
          window.setTimeout(() => render(html));
          return;
        }
        if (!uncached.has(id)) {
          uncached.set(id, {
            token: element.dataset.contextualToken,
            render: [],
          });
        }
        uncached.get(id).render.push(render);
      });

      if (uncached.size) {
        const data = new URLSearchParams();
        uncached.forEach(({ token }, id) => {
          data.append('ids[]', id);
          data.append('tokens[]', token);
        });

        const controllerUrl = `contextual/render?theme=${options.theme}`;
        const request = new Request(Drupal.url(controllerUrl), {
          method: 'POST',
          body: data,
        });

        fetch(request)
          .then((response) => response.json())
          .then((json) => {
            Object.entries(json).forEach(([id, html]) => {
              storage.setItem(`Drupal.contextual.${id}.${options.theme}`, html);
              uncached.get(id)?.render.forEach((render) => render(html));
            });
          })
          .catch((error) => console.warn(error));
      }
    },

    detach(context, settings, trigger) {
      if (trigger !== 'unload') {
        return;
      }

      const elements = once.remove('contextual-render', [
        ...(context instanceof Element ? [context] : []),
        ...context.querySelectorAll('[data-contextual-id]'),
      ]);
      elements.forEach((element) => pendingPlaceholders.delete(element));
      const { instances } = Drupal.contextual;
      for (let index = instances.length - 1; index >= 0; index--) {
        const model = instances[index];
        if (model.element === context || context.contains(model.element)) {
          model.destroy();
          instances.splice(index, 1);
          window.dispatchEvent(
            new CustomEvent('contextual-instances-removed', {
              detail: { model },
            }),
          );
        }
      }
    },
  };

  /**
   * Namespace for contextual related functionality.
   *
   * @namespace
   *
   * @private
   */
  Drupal.contextual = {
    instances: [],

    /**
     * Models the state of a contextual link's trigger, list & region.
     */
    ContextualModelView: class {
      constructor(element, region, options) {
        this.title = options.title || '';
        this.regionIsHovered = false;
        this._hasFocus = false;
        this._isOpen = false;
        this._isLocked = false;
        this.strings = options.strings;
        this.timer = NaN;
        this.modelId = btoa(Math.random()).substring(0, 12);
        this.region = region;
        this.element = element;
        this.trigger = element.querySelector('.trigger');
        this.links = element.querySelector('.contextual-links');
        this.listeners = new AbortController();

        const listenerOptions = { signal: this.listeners.signal };
        if (!document.body.classList.contains('touchevents')) {
          region?.addEventListener(
            'mouseenter',
            () => {
              this.regionIsHovered = true;
              this.render();
            },
            listenerOptions,
          );
          region?.addEventListener(
            'mouseleave',
            () => {
              this.close().blur();
              this.regionIsHovered = false;
              this.render();
            },
            listenerOptions,
          );
          element.addEventListener(
            'mouseenter',
            () => {
              this.focus();
              this.render();
            },
            listenerOptions,
          );
        }

        const listeners = (target, handlers) => {
          Object.entries(handlers).forEach(([type, handler]) => {
            target.addEventListener(
              type,
              (event) => {
                handler(event);
                this.render();
              },
              listenerOptions,
            );
          });
        };
        listeners(this.trigger, {
          click: () => this.toggleOpen(),
          touchend: Drupal.contextual.ContextualModelView.touchEndToClick,
          focus: () => this.focus(),
          blur: () => this.blur(),
        });
        // Ajax links stop the click before it reaches the contextual wrapper.
        element.addEventListener(
          'click',
          (event) => {
            if (this.links?.contains(event.target.closest('a'))) {
              this.close().blur();
              this.render();
            }
          },
          listenerOptions,
        );
        this.links?.querySelectorAll('a').forEach((link) =>
          listeners(link, {
            touchend: Drupal.contextual.ContextualModelView.touchEndToClick,
            focus: () => this.focus(),
            blur: () => this.waitCloseThenBlur(),
          }),
        );

        this.render();

        // Bubble to both document and window listeners.
        document.dispatchEvent(
          new CustomEvent('drupalContextualLinkAdded', {
            bubbles: true,
            detail: { element, region, model: this },
          }),
        );
      }

      /**
       * Releases the listeners and delayed blur when a contextual link unloads.
       */
      destroy() {
        this.listeners.abort();
        window.clearTimeout(this.timer);
        this.close().blur();
        this.render();
      }

      /**
       * Updates the rendered representation of the current contextual links.
       */
      render() {
        const { isOpen } = this;
        const isVisible = this.isLocked || this.regionIsHovered || isOpen;
        this.region?.classList.toggle('focus', this.hasFocus);
        this.element.classList.toggle('open', isOpen);
        this.trigger.classList.toggle('visually-hidden', !isVisible);
        if (this.links) {
          this.links.hidden = !isOpen;
        }
        this.trigger.textContent = Drupal.t(
          '@action @title configuration options',
          {
            '@action': !isOpen ? this.strings.open : this.strings.close,
            '@title': this.title,
          },
        );
        this.trigger.setAttribute('aria-pressed', isOpen);
      }

      /**
       * Prevents delay and simulated mouse events.
       *
       * @param {TouchEvent} event
       *   The touchend event.
       */
      static touchEndToClick(event) {
        event.preventDefault();
        event.currentTarget.click();
      }

      /**
       * Set up a timeout to allow a user to tab between the trigger and the
       * contextual links without the menu dismissing.
       */
      waitCloseThenBlur() {
        this.timer = window.setTimeout(() => {
          this.isOpen = false;
          this.hasFocus = false;
          this.render();
        }, 150);
      }

      /**
       * Opens or closes the contextual link.
       *
       * If it is opened, then also give focus.
       *
       * @return {Drupal.contextual.ContextualModelView}
       *   The current contextual model view.
       */
      toggleOpen() {
        const newIsOpen = !this.isOpen;
        this.isOpen = newIsOpen;
        if (newIsOpen) {
          this.focus();
        }
        return this;
      }

      /**
       * Gives focus to this contextual link.
       *
       * Also closes + removes focus from every other contextual link.
       *
       * @return {Drupal.contextual.ContextualModelView}
       *   The current contextual model view.
       */
      focus() {
        const { modelId } = this;
        Drupal.contextual.instances.forEach((model) => {
          if (model.modelId !== modelId) {
            model.close().blur();
          }
        });
        window.clearTimeout(this.timer);
        this.hasFocus = true;
        return this;
      }

      /**
       * Removes focus from this contextual link, unless it is open.
       *
       * @return {Drupal.contextual.ContextualModelView}
       *   The current contextual model view.
       */
      blur() {
        if (!this.isOpen) {
          this.hasFocus = false;
        }
        return this;
      }

      /**
       * Closes this contextual link.
       *
       * Does not call blur() because we want to allow a contextual link to have
       * focus, yet be closed for example when hovering.
       *
       * @return {Drupal.contextual.ContextualModelView}
       *   The current contextual model view.
       */
      close() {
        this.isOpen = false;
        return this;
      }

      /**
       * Gets the current focus state.
       *
       * @return {boolean} the focus state.
       */
      get hasFocus() {
        return this._hasFocus;
      }

      /**
       * Sets the current focus state.
       *
       * @param {boolean} value - new focus state
       */
      set hasFocus(value) {
        this._hasFocus = value;
        this.region?.classList.toggle('focus', this._hasFocus);
      }

      /**
       * Gets the current open state.
       *
       * @return {boolean} the open state.
       */
      get isOpen() {
        return this._isOpen;
      }

      /**
       * Sets the current open state.
       *
       * @param {boolean} value - new open state
       */
      set isOpen(value) {
        this._isOpen = value;
        // Nested contextual region handling: hide any nested contextual triggers.
        const triggers = this.region?.querySelectorAll('.contextual .trigger');
        triggers?.forEach((trigger, index) => {
          if (index > 0) {
            trigger.style.display = this.isOpen ? 'none' : '';
          }
        });
      }

      /**
       * Gets the current locked state.
       *
       * @return {boolean} the locked state.
       */
      get isLocked() {
        return this._isLocked;
      }

      /**
       * Sets the current locked state.
       *
       * @param {boolean} value - new locked state
       */
      set isLocked(value) {
        if (value !== this._isLocked) {
          this._isLocked = value;
          this.render();
        }
      }
    },
  };

  /**
   * A trigger is an interactive element often bound to a click handler.
   *
   * @return {string}
   *   A string representing a DOM fragment.
   */
  Drupal.theme.contextualTrigger = function () {
    return '<button class="trigger visually-hidden focusable" type="button"></button>';
  };

  /**
   * Bind Ajax contextual links when added.
   *
   * @listens event:drupalContextualLinkAdded
   */
  document.addEventListener('drupalContextualLinkAdded', ({ detail }) => {
    Drupal.ajax?.bindAjaxLinks(detail.element);
  });
})(Drupal, drupalSettings, once, window.sessionStorage);
