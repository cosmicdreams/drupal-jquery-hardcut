/**
 * @file
 * Drupal dialogs backed by the native dialog element.
 */

(function (Drupal, drupalSettings, { isFocusable, tabbable }, bodyScrollLock) {
  const dialogs = new WeakMap();
  let titleId = 0;
  const dimensions = [
    'width',
    'height',
    'minWidth',
    'minHeight',
    'maxWidth',
    'maxHeight',
  ];

  drupalSettings.dialog = {
    width: 300,
    buttonClass: 'button',
    buttonPrimaryClass: 'button--primary',
    ...drupalSettings.dialog,
  };

  function cssLength(value) {
    return /^\d+(\.\d+)?$/.test(value) ? `${value}px` : value;
  }

  /**
   * Creates or retrieves the dialog associated with the content element.
   *
   * @param {HTMLElement} content
   *   The dialog content.
   * @param {object} [options]
   *   Dialog settings.
   *
   * @return {object}
   *   The dialog controller.
   */
  Drupal.dialog = function (content, options) {
    if (dialogs.has(content)) {
      const dialog = dialogs.get(content);
      if (options) {
        dialog.update(options);
      }
      return dialog;
    }

    const element = document.createElement('dialog');
    const titlebar = document.createElement('div');
    const closeButton = document.createElement('button');
    const pane = document.createElement('div');
    const buttons = document.createElement('div');
    const settings = { ...drupalSettings.dialog, ...options };
    let opener;
    let openerContainer;
    let resizeObserver;
    let scrollTarget;
    let closing = false;
    let title;

    titlebar.className = 'drupal-dialog-titlebar';
    closeButton.className = 'drupal-dialog-titlebar-close';
    closeButton.type = 'button';
    closeButton.textContent = Drupal.t('Close');
    closeButton.title = closeButton.textContent;
    titlebar.append(closeButton);
    pane.className = 'drupal-dialog-buttonpane';
    buttons.className = 'drupal-dialog-buttonset form-actions';
    pane.append(buttons);
    element.append(titlebar, content, pane);
    content.hidden = false;
    content.classList.add('drupal-dialog-content');

    function emit(type) {
      const event = new Event(`dialog:${type}`, { bubbles: true });
      event.dialog = dialog;
      event.settings = settings;
      content.dispatchEvent(event);
    }

    function renderButtons() {
      buttons.replaceChildren();
      for (const definition of settings.buttons || []) {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = definition.text || '';
        button.className = [settings.buttonClass, definition.class]
          .filter(Boolean)
          .join(' ');
        if (definition.primary) {
          button.classList.add(settings.buttonPrimaryClass);
        }
        if (definition['data-once']) {
          button.dataset.once = definition['data-once'];
        }
        if (definition.disabled) {
          button.disabled = true;
        }
        if (typeof definition.click === 'function') {
          button.addEventListener('click', definition.click);
        }
        buttons.append(button);
      }
      pane.hidden = !buttons.childElementCount;
    }

    function update(options) {
      Object.assign(settings, options);
      const heading = settings.modal ? 'h1' : 'h2';
      if (title?.localName !== heading) {
        title?.remove();
        title = document.createElement(heading);
        title.className = 'drupal-dialog-title';
        title.id = `drupal-dialog-title-${++titleId}`;
        titlebar.prepend(title);
      }
      // Titles contain escaped markup. Decode entities and display only text.
      const template = document.createElement('template');
      template.innerHTML = settings.title || '';
      title.textContent = template.content.textContent;
      if (title.textContent) {
        element.setAttribute('aria-labelledby', title.id);
        element.removeAttribute('aria-label');
      } else {
        element.removeAttribute('aria-labelledby');
        element.setAttribute('aria-label', Drupal.t('Dialog'));
      }
      title.hidden = !title.textContent;
      closeButton.hidden = settings.closeButton === false;
      element.className = ['drupal-dialog', settings.classes?.['drupal-dialog']]
        .filter(Boolean)
        .join(' ');
      // Autocomplete looks for the closest stacking context for its menu.
      element.classList.add('ui-front');
      element.classList.toggle(
        'drupal-dialog--viewport-centered',
        settings.autoResize === false || settings.autoResize === 'false',
      );
      const previousContentClasses = (content.dataset.dialogClasses || '')
        .split(/\s+/)
        .filter(Boolean);
      content.classList.remove(...previousContentClasses);
      content.classList.add('drupal-dialog-content');
      content.dataset.dialogClasses =
        settings.classes?.['drupal-dialog-content'] || '';
      content.classList.add(
        ...content.dataset.dialogClasses.split(/\s+/).filter(Boolean),
      );
      for (const dimension of dimensions) {
        element.style[dimension] = settings[dimension]
          ? cssLength(settings[dimension])
          : '';
      }
      if (!element.open || Object.hasOwn(options, 'buttons')) {
        renderButtons();
      }
    }

    function focusInitial() {
      const target =
        content.querySelector('[autofocus]') ||
        tabbable(content)[0] ||
        tabbable(pane)[0] ||
        (!closeButton.hidden && closeButton.getClientRects().length
          ? closeButton
          : element);
      target.focus({ preventScroll: true });
    }

    function open(modal) {
      if (element.open) {
        return;
      }
      opener = settings.opener || document.activeElement;
      openerContainer = opener?.closest('[data-drupal-ajax-container]');
      settings.modal = modal;
      emit('beforecreate');
      update({});
      element.returnValue = '';
      if (modal) {
        element.showModal();
        scrollTarget = settings.scrollTarget || content;
        bodyScrollLock.lock(scrollTarget);
      } else {
        element.show();
      }
      focusInitial();
      resizeObserver = new ResizeObserver(() => {
        content.dispatchEvent(
          new Event('dialogContentResize', { bubbles: true }),
        );
      });
      resizeObserver.observe(element);
      emit('aftercreate');
    }

    function close(value) {
      if (!element.open || closing) {
        return;
      }
      closing = true;
      emit('beforeclose');
      if (settings.modal) {
        bodyScrollLock.unlock(scrollTarget);
      }
      resizeObserver.disconnect();
      element.close(value);
      if (settings.removeOnClose) {
        Drupal.detachBehaviors(content, null, 'unload');
      }
      emit('afterclose');
      if (settings.removeOnClose) {
        element.remove();
      }
      // An Ajax command can disable or replace the original trigger.
      if (opener?.isConnected && isFocusable(opener)) {
        if (document.activeElement === document.body) {
          opener.focus({ preventScroll: true });
        }
      } else if (openerContainer) {
        tabbable(openerContainer)[0]?.focus({ preventScroll: true });
      }
      closing = false;
    }

    const dialog = {
      element,
      get open() {
        return element.open;
      },
      get returnValue() {
        return element.returnValue;
      },
      show: () => open(false),
      showModal: () => open(true),
      close,
      update,
      settings,
      focusInitial,
    };

    dialogs.set(content, dialog);
    document.body.append(element);
    content.addEventListener('click', (event) => {
      if (event.target.closest('.dialog-cancel')) {
        event.preventDefault();
        event.stopPropagation();
        close('cancel');
      }
    });
    closeButton.addEventListener('click', () => close());
    element.addEventListener('cancel', (event) => {
      event.preventDefault();
      if (settings.closeOnEscape !== false) {
        close();
      }
    });
    element.addEventListener('keydown', (event) => {
      if (!settings.modal && !event.defaultPrevented) {
        if (event.key === 'Escape' && settings.closeOnEscape !== false) {
          event.preventDefault();
          close();
        } else if (event.key === 'Tab') {
          const targets = tabbable(element);
          const boundary = event.shiftKey ? targets[0] : targets.at(-1);
          if (
            !boundary ||
            event.target === boundary ||
            event.target === element
          ) {
            event.preventDefault();
            (event.shiftKey ? targets.at(-1) : targets[0])?.focus();
          }
        }
      }
    });
    return dialog;
  };

  Drupal.dialog.get = (element) => dialogs.get(element);
})(Drupal, drupalSettings, window.tabbable, bodyScrollLock);
