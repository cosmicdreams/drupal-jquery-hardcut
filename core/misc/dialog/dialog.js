/**
 * @file
 * Drupal dialogs backed by the native dialog element.
 */

(function (Drupal, drupalSettings, { isFocusable, tabbable }, bodyScrollLock) {
  const dialogs = new WeakMap();
  const openSessions = new WeakMap();
  const openModals = new Set();
  const latestOpen = new Map();
  let openGeneration = 0;
  let pendingOpens = 0;
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

  function isPercentage(value) {
    return /^\d+(\.\d+)?%$/.test(value);
  }

  function availableHeight(percentage) {
    const fraction = parseFloat(percentage) / 100;
    return `calc(${percentage} - ${fraction} * var(--drupal-dialog-displaced-height))`;
  }

  function nextOpenGeneration() {
    openGeneration += 1;
    return openGeneration;
  }

  function targetKey(target) {
    const content =
      typeof target === 'string' ? document.querySelector(target) : target;
    return content?.id ? `#${CSS.escape(content.id)}` : target;
  }

  function isLatestOpen(target, generation) {
    return generation >= (latestOpen.get(targetKey(target)) ?? -Infinity);
  }

  function claimOpen(target, generation) {
    if (!isLatestOpen(target, generation)) {
      return false;
    }
    latestOpen.set(targetKey(target), generation);
    return true;
  }

  function forgetSettledOpens() {
    // Ajax may have started before the dialog library was loaded.
    if (!pendingOpens && !Drupal.ajax?.pendingRequests().next().value) {
      latestOpen.clear();
    }
  }

  function requestOrigin(source) {
    const element = source?.closest?.('dialog[open]');
    if (openSessions.has(element)) {
      return { element, generation: openSessions.get(element) };
    }
  }

  function hasOpenOrigin(origin) {
    return (
      !origin ||
      (origin.element.isConnected &&
        origin.element.open &&
        openSessions.get(origin.element) === origin.generation)
    );
  }

  function trackAjaxRequest(ajax) {
    const { dialogType, dialogRenderer, dialog } = ajax.elementSettings;
    const source = ajax.element || (dialogType && document.activeElement);
    ajax.dialogGeneration = nextOpenGeneration();
    ajax.dialogOrigin = requestOrigin(source);
    let target;
    if (
      dialogRenderer === 'off_canvas' ||
      dialogRenderer === 'off_canvas_top'
    ) {
      target = '#drupal-off-canvas';
    } else if (dialogType === 'modal') {
      target = '#drupal-modal';
    } else if (dialogType === 'dialog' && dialog?.target) {
      target = `#${CSS.escape(dialog.target.replace(/^#/, ''))}`;
    }
    if (target) {
      claimOpen(target, ajax.dialogGeneration);
    }
  }

  function claimAjaxResponse(ajax, response) {
    if (!hasOpenOrigin(ajax.dialogOrigin)) {
      return;
    }
    // Some requests only identify the target in the response. Claim it
    // before preceding commands can wait for assets or start another open.
    Object.values(response || {}).forEach(({ command, selector }) => {
      if (command === 'openDialog' && selector) {
        claimOpen(selector, ajax.dialogGeneration);
      } else if (command === 'openModalDialogWithUrl') {
        claimOpen('#drupal-modal', ajax.dialogGeneration);
      }
    });
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
    let dialogClasses = [];

    element.className = 'drupal-dialog';
    titlebar.className = 'drupal-dialog-titlebar';
    closeButton.className = 'drupal-dialog-titlebar-close';
    closeButton.type = 'button';
    closeButton.textContent = Drupal.t('Close');
    closeButton.title = closeButton.textContent;
    closeButton.setAttribute('aria-label', closeButton.textContent);
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
        if (definition['data-dialog-button-for']) {
          button.dataset.dialogButtonFor = definition['data-dialog-button-for'];
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
      title.textContent = settings.title ?? '';
      if (title.textContent) {
        element.setAttribute('aria-labelledby', title.id);
        element.removeAttribute('aria-label');
      } else {
        element.removeAttribute('aria-labelledby');
        element.setAttribute('aria-label', Drupal.t('Dialog'));
      }
      title.hidden = !title.textContent;

      const description = content.getAttribute('aria-describedby');
      if (description) {
        element.setAttribute('aria-describedby', description);
      } else if (
        content.textContent.trim() &&
        !content.querySelector('[aria-describedby]')
      ) {
        // Use the content as the description when it has no description markup.
        content.id = content.id || `${title.id}-description`;
        element.setAttribute('aria-describedby', content.id);
      } else {
        element.removeAttribute('aria-describedby');
      }

      closeButton.hidden = settings.closeButton === false;
      element.classList.remove(...dialogClasses);
      dialogClasses = (settings.classes?.['drupal-dialog'] || '')
        .split(/\s+/)
        .filter(Boolean);
      element.classList.add('drupal-dialog', ...dialogClasses);
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
      for (const dimension of ['minHeight', 'maxHeight']) {
        if (isPercentage(settings[dimension])) {
          element.style[dimension] = availableHeight(settings[dimension]);
        }
      }
      if (isPercentage(settings.height)) {
        // Let short content size the dialog and cap taller content.
        element.style.height = '';
        const limit = availableHeight(settings.height);
        const maxHeight =
          element.style.maxHeight || 'var(--drupal-dialog-viewport-max-height)';
        element.style.maxHeight =
          maxHeight === 'none' ? limit : `min(${limit}, ${maxHeight})`;
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

    function open(modal, generation) {
      if (!claimOpen(content, generation)) {
        return;
      }
      if (element.open) {
        return;
      }
      opener = settings.opener || document.activeElement;
      openerContainer = opener?.closest('[data-drupal-ajax-container]');
      settings.modal = modal;
      emit('beforecreate');
      if (!isLatestOpen(content, generation) || !element.isConnected) {
        return;
      }
      update({});
      element.returnValue = '';
      openSessions.set(element, generation);
      const observer = new ResizeObserver(() => {
        if (observer === resizeObserver && element.open) {
          content.dispatchEvent(
            new Event('dialogContentResize', { bubbles: true }),
          );
        }
      });
      resizeObserver = observer;
      observer.observe(element);
      if (modal) {
        scrollTarget = settings.scrollTarget || content;
        bodyScrollLock.lock(scrollTarget);
        openModals.add(element);
        element.showModal();
      } else {
        element.show();
      }
      // The browser moves focus during show(), and focus handlers can close
      // the dialog before it returns.
      if (openSessions.get(element) === generation) {
        focusInitial();
        if (openSessions.get(element) === generation) {
          emit('aftercreate');
        }
      }
    }

    function close(value) {
      if (!element.open || closing) {
        return;
      }
      closing = true;
      openSessions.delete(element);
      emit('beforeclose');
      if (settings.modal) {
        bodyScrollLock.unlock(scrollTarget);
      }
      resizeObserver.disconnect();
      resizeObserver = null;
      element.close(value);
      openModals.delete(element);
      if (settings.removeOnClose) {
        Drupal.detachBehaviors(content, null, 'unload');
      }
      emit('afterclose');
      if (settings.removeOnClose) {
        element.remove();
      }
      // An Ajax command can disable or replace the original trigger.
      const focused = document.activeElement;
      if (!focused || !isFocusable(focused)) {
        const target =
          opener?.isConnected && isFocusable(opener)
            ? opener
            : openerContainer && tabbable(openerContainer)[0];
        target?.focus({ preventScroll: true });
      }
      closing = false;
    }

    function show(modal, generation = nextOpenGeneration()) {
      // Lifecycle listeners can open the same target before this call returns.
      pendingOpens += 1;
      try {
        open(modal, generation);
      } finally {
        pendingOpens -= 1;
        forgetSettledOpens();
      }
    }

    const dialog = {
      element,
      get open() {
        return element.open;
      },
      get returnValue() {
        return element.returnValue;
      },
      show: (generation) => show(false, generation),
      showModal: (generation) => show(true, generation),
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
      if (event.defaultPrevented) {
        return;
      }
      if (
        !settings.modal &&
        event.key === 'Escape' &&
        settings.closeOnEscape !== false
      ) {
        event.preventDefault();
        close();
      } else if (event.key === 'Tab') {
        // Keep keyboard focus from leaving the dialog for the browser chrome.
        const targets = tabbable(element);
        const first = targets[0];
        const last = targets.at(-1);
        const boundary = event.shiftKey ? first : last;
        if (
          !boundary ||
          event.target === boundary ||
          event.target === element
        ) {
          event.preventDefault();
          (event.shiftKey ? last : first)?.focus();
        }
      }
    });
    return dialog;
  };

  Object.assign(Drupal.dialog, {
    get: (element) => dialogs.get(element),
    getOpenModal: () =>
      Array.from(openModals).findLast((element) => element.matches(':modal')),
    claimOpen,
    isLatestOpen,
    requestOrigin,
    hasOpenOrigin,
    trackAjaxRequest,
    claimAjaxResponse,
    beginOpenRequest(target) {
      pendingOpens += 1;
      const generation = nextOpenGeneration();
      claimOpen(target, generation);
      return generation;
    },
    finishOpenRequest() {
      pendingOpens -= 1;
      forgetSettledOpens();
    },
  });
  // Adopt requests that started before the dialog controller loaded, in
  // send order, before taking a token for any new open.
  for (const ajax of Drupal.ajax?.pendingRequests() || []) {
    trackAjaxRequest(ajax);
  }
  document.addEventListener('drupal:ajax-complete', forgetSettledOpens);
})(Drupal, drupalSettings, window.tabbable, bodyScrollLock);
