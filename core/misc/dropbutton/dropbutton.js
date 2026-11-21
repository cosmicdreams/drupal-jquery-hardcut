/**
 * @file
 * Dropbutton feature.
 */

((Drupal) => {
  /**
   * Connects native dropbutton interactions to the event registry.
   */
  function listen(target, type, listener, namespace, selector) {
    const binding = {
      target,
      type,
      listener,
      namespace,
      selector,
      formInteraction: true,
      stop() {
        target.removeEventListener(type, handle);
        unregister();
      },
    };
    const handle = (event) => {
      if (!binding.handlesNativeEvent) {
        listener(event);
      }
    };
    target.addEventListener(type, handle);
    const unregister = Drupal.eventBindings.register(binding);
  }

  /**
   * Presents a list as a primary button with optional secondary actions.
   *
   * @constructor Drupal.DropButton
   * @param {HTMLElement} dropbutton
   *   The wrapper element.
   * @param {object} settings
   *   Options including the translated toggle title.
   */
  function DropButton(dropbutton, settings) {
    const options = {
      ...settings,
      title:
        settings?.title === undefined
          ? Drupal.t('List additional actions')
          : settings.title,
    };
    this.wrapperElement = dropbutton;
    this.listElements = Array.from(dropbutton.querySelectorAll('.dropbutton'));
    this.actionElements = Array.from(
      dropbutton.querySelectorAll('.dropbutton li'),
    );
    this.actionElements.forEach((action) =>
      action.classList.add('dropbutton-action'),
    );

    if (this.actionElements.length > 1) {
      const [primary, ...secondary] = this.actionElements;
      secondary.forEach((action) => action.classList.add('secondary-action'));
      primary.insertAdjacentHTML(
        'afterend',
        Drupal.theme('dropbuttonToggle', options),
      );
      dropbutton.classList.add('dropbutton-multiple');
      const handlers = {
        mouseleave: this.hoverOutNative,
        mouseenter: this.hoverInNative,
        focusout: this.focusOutNative,
        focusin: this.focusInNative,
      };
      Object.entries(handlers).forEach(([type, handler]) => {
        listen(dropbutton, type, handler.bind(this), 'dropbutton');
      });
    } else {
      dropbutton.classList.add('dropbutton-single');
    }
  }

  /**
   * Toggles a dropdown when a delegated toggle is activated.
   */
  function toggleSecondaryActions(event) {
    if (event.button >= 1) {
      return;
    }
    const toggle = event.target.closest?.('.dropbutton-toggle');
    const wrapper = toggle?.closest('.dropbutton-wrapper');
    if (wrapper) {
      event.preventDefault();
      wrapper.classList.toggle('open');
    }
  }

  Drupal.behaviors.dropButton = {
    attach(context, settings) {
      const dropbuttons = once('dropbutton', '.dropbutton-wrapper', context);
      if (!dropbuttons.length) {
        return;
      }
      once('dropbutton-click', 'body').forEach((body) => {
        listen(
          body,
          'click',
          toggleSecondaryActions,
          undefined,
          '.dropbutton-toggle',
        );
      });
      dropbuttons.forEach((dropbutton) => {
        DropButton.dropbuttons.push(
          new DropButton(dropbutton, settings.dropbutton),
        );
      });
    },
  };

  DropButton.dropbuttons = [];

  DropButton.prototype = {
    /**
     * Toggles the dropbutton or applies the specified state.
     */
    toggle(show) {
      const open =
        typeof show === 'boolean'
          ? show
          : !this.wrapperElement.classList.contains('open');
      this.wrapperElement.classList.toggle('open', open);
    },

    /**
     * Cancels a pending close. Override this method for a native event.
     */
    hoverInNative() {
      if (this.timerID) {
        window.clearTimeout(this.timerID);
      }
    },

    /**
     * Schedules a close. Override this method for a native event.
     */
    hoverOutNative() {
      this.timerID = window.setTimeout(this.close.bind(this), 500);
    },

    /**
     * Opens the secondary actions.
     */
    open() {
      this.toggle(true);
    },

    /**
     * Closes the secondary actions.
     */
    close() {
      this.toggle(false);
    },

    /**
     * Handles native focus leaving the dropbutton.
     */
    focusOutNative(event) {
      this.hoverOutNative(event);
    },

    /**
     * Handles native focus entering the dropbutton.
     */
    focusInNative(event) {
      this.hoverInNative(event);
    },
  };

  /**
   * Returns markup for the toggle button.
   */
  Drupal.theme.dropbuttonToggle = (options) =>
    `<li class="dropbutton-toggle"><button type="button"><span class="dropbutton-arrow"><span class="visually-hidden">${options.title}</span></span></button></li>`;

  Drupal.DropButton = DropButton;
  document.dispatchEvent(new Event('drupal:dropbutton-ready'));
})(Drupal);
