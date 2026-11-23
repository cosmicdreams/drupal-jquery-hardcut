/**
 * @file
 * Handles AJAX fetching of views, including filter submission and response.
 */

/**
 * Binds Views Ajax behaviors and response commands.
 * @param {Drupal} Drupal
 *   The Drupal object.
 * @param {object} drupalSettings
 *   Settings for the current Drupal page.
 * @param {typeof window.once} once
 *   Marks elements already processed by a behavior.
 */
((Drupal, drupalSettings, once) => {
  Drupal.behaviors.ViewsAjaxView = {
    attach(context, settings) {
      Object.values(settings?.views?.ajaxViews ?? {}).forEach(bindView);
    },
  };

  /**
   * Binds the exposed forms, pager links and refresh event for a view.
   * @param {object} settings
   *   The view's Ajax settings.
   */
  function bindView(settings) {
    const selector = `.js-view-dom-id-${settings.view_dom_id}`;
    const view = document.querySelector(selector);
    if (!view) {
      return;
    }
    const formSelector = `form#views-exposed-form-${settings.view_name.replaceAll('_', '-')}-${settings.view_display_id.replaceAll('_', '-')}`;
    const inlineForms = view.querySelectorAll(formSelector);
    const exposedForms = Array.from(
      inlineForms.length
        ? inlineForms
        : document.querySelectorAll(formSelector),
    );

    // Retrieve the path to use for views' ajax.
    let ajaxPath = drupalSettings.views.ajax_path;

    // If there are multiple views this might've ended up showing up multiple
    // times.
    if (Array.isArray(ajaxPath)) {
      ajaxPath = ajaxPath[0];
    }

    // Submitting filters resets paging and sorting. Remove all previous
    // values of exposed fields, including multi-select array parameters.
    const stripArraySuffix = (name) => name.split('[')[0];
    const excluded = new Set([
      'page',
      'reset',
      'sort',
      'order',
      'q',
      'render',
      ...exposedForms.flatMap((form) =>
        Array.from(form.elements, ({ name }) => stripArraySuffix(name)),
      ),
    ]);
    const url = new URL(ajaxPath, document.baseURI);
    new URLSearchParams(window.location.search).forEach((value, name) => {
      if (!excluded.has(stripArraySuffix(name))) {
        url.searchParams.append(name, value);
      }
    });

    const defaults = {
      url: url.href,
      submit: settings,
      httpMethod: 'GET',
      setClick: true,
      event: 'click',
      selector,
    };

    // Separate exposed blocks can outlive the view markup.
    const submit =
      ':is(input[type=submit], button[type=submit], input[type=image]):not([data-drupal-selector=edit-reset])';
    once(
      'views-exposed',
      exposedForms.flatMap((form) => [...form.querySelectorAll(submit)]),
    ).forEach((element) => {
      element.dataset.refocusBlur = 'true';
      bindRequest({ ...defaults, base: element.id, element });
    });

    if (!view.parentElement?.closest('.view')) {
      once(
        'views-pager',
        '.js-pager__items a[href], th.views-field a[href], .attachment .views-summary a[href]',
        view,
      ).forEach((element) => {
        const href = new URL(element.href);
        bindRequest({
          ...defaults,
          base: false,
          element,
          submit: {
            ...settings,
            ...Drupal.Views.parseQueryString(href.search),
            ...Drupal.Views.parseViewArgs(href.href, settings.view_base_path),
          },
        });
      });
    }

    // Dispatch RefreshView on the view element to update it in place.
    once('views-refresh', view).forEach((element) => {
      bindRequest({ ...defaults, element, event: 'RefreshView' });
    });
  }

  const latestRequests = new WeakMap();

  /**
   * Binds an Ajax request and discards responses superseded for the same view.
   * @param {object} settings
   *   The settings for this request.
   */
  function bindRequest(settings) {
    const ajax = Drupal.ajax({
      ...settings,
      progress: { type: 'fullscreen' },
    });
    let target;
    let request;
    const { beforeSend } = ajax.options;
    ajax.options.beforeSend = function (xhr, options) {
      target = document.querySelector(ajax.selector);
      const result = beforeSend.call(this, xhr, options);
      if (target && result !== false) {
        request = Symbol();
        latestRequests.set(target, request);
      }
      return result;
    };
    const { success } = ajax;
    ajax.success = function (response, status) {
      if (
        !target?.isConnected ||
        latestRequests.get(target) !== request ||
        !this.element.isConnected ||
        (this.dialogOrigin && !Drupal.dialog.hasOpenOrigin(this.dialogOrigin))
      ) {
        // Use the common cleanup without changing the current focus.
        const focus = Drupal.ajax.elementData.get(
          this.element,
          'disable-refocus',
        );
        this.preCommandsFocusedElementSelector = null;
        Drupal.ajax.elementData.set(this.element, 'disable-refocus', true);
        return success.call(this, [], status).finally(() => {
          Drupal.ajax.elementData.set(this.element, 'disable-refocus', focus);
        });
      }
      return success.call(this, response, status);
    };
  }

  /**
   * Sets the browser URL ajax command.
   * @param {Drupal.Ajax} [ajax]
   *   A {@link Drupal.ajax} object.
   * @param {object} response
   *   Ajax response.
   * @param {string} response.url
   *   URL to be set.
   */
  Drupal.AjaxCommands.prototype.setBrowserUrl = (ajax, response) => {
    // Do not change browser URL if we are in a dialog wrapper.
    if (
      ajax.element &&
      !ajax.dialogOrigin &&
      !ajax.element.closest('.drupal-dialog-content')
    ) {
      window.history.replaceState(null, '', response.url);
    }
  };
})(Drupal, drupalSettings, once);
