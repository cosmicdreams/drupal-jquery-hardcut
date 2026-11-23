/**
 * @file
 * Handles AJAX fetching of views, including filter submission and response.
 */

((Drupal, drupalSettings, once) => {
  /**
   * Attaches the AJAX behavior to exposed filters forms and key View links.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches ajaxView functionality to relevant elements.
   */
  Drupal.behaviors.ViewsAjaxView = {};
  Drupal.behaviors.ViewsAjaxView.attach = function (context, settings) {
    Object.entries(settings?.views?.ajaxViews ?? {}).forEach(([key, config]) => {
      const instance = new Drupal.views.ajaxView(config);
      if (instance.view) {
        Drupal.views.instances[key] = instance;
      }
    });
  };
  Drupal.behaviors.ViewsAjaxView.detach = (context, settings, trigger) => {
    if (trigger === 'unload') {
      Object.entries(Drupal.views.instances).forEach(([key, { view }]) => {
        if (context.contains(view)) {
          delete Drupal.views.instances[key];
        }
      });
    }
  };

  /**
   * @namespace
   */
  Drupal.views = {};

  /**
   * @type {object.<string, Drupal.views.ajaxView>}
   */
  Drupal.views.instances = {};

  /**
   * JavaScript object for a certain view.
   *
   * @constructor
   *
   * @param {object} settings
   *   Settings object for the ajax view.
   * @param {string} settings.view_dom_id
   *   The DOM id of the view.
   */
  Drupal.views.ajaxView = function (settings) {
    const selector = `.js-view-dom-id-${settings.view_dom_id}`;
    this.view = document.querySelector(selector);
    if (!this.view) {
      return;
    }
    this.exposedForms = [
      ...document.querySelectorAll(
        `form#views-exposed-form-${settings.view_name.replace(
          /_/g,
          '-',
        )}-${settings.view_display_id.replace(/_/g, '-')}`,
      ),
    ];

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
      'page', 'reset', 'sort', 'order', 'q', 'render',
      ...this.exposedForms.flatMap((form) =>
        Array.from(form.elements, ({ name }) => stripArraySuffix(name)),
      ),
    ]);
    const url = new URL(ajaxPath, document.baseURI);
    new URLSearchParams(window.location.search).forEach((value, name) => {
      if (!excluded.has(stripArraySuffix(name))) {
        url.searchParams.append(name, value);
      }
    });

    this.element_settings = {
      url: url.href,
      submit: settings,
      httpMethod: 'GET',
      setClick: true,
      event: 'click',
      selector,
      progress: { type: 'fullscreen' },
    };

    this.settings = settings;

    // Separate exposed blocks can outlive the view markup.
    const submit = ':is(input[type=submit], button[type=submit], input[type=image]):not([data-drupal-selector=edit-reset])';
    once(
      `views-exposed-${settings.view_dom_id}`,
      this.exposedForms.flatMap((form) => [...form.querySelectorAll(submit)]),
    ).forEach((element) => {
      Drupal.ajax.elementData.set(element, 'refocus-blur', true);
      this.bind({ base: element.id, element });
    });

    if (!this.view.parentElement?.closest('.view')) {
      once(
        'views-pager',
        '.js-pager__items a[href], th.views-field a[href], .attachment .views-summary a[href]',
        this.view,
      ).forEach((element) => {
        const href = new URL(element.href);
        this.bind({
          base: false,
          element,
          submit: {
            ...this.settings,
            ...Drupal.Views.parseQueryString(href.search),
            ...Drupal.Views.parseViewArgs(href.href, this.settings.view_base_path),
          },
        });
      });
    }

    // Dispatch RefreshView on the view element to update it in place.
    once('views-refresh', this.view).forEach((element) => {
      this.refreshViewAjax = this.bind({ element, event: 'RefreshView' });
    });
  };

  const latestRequests = new WeakMap();

  /**
   * Binds an Ajax request and discards responses superseded for the same view.
   */
  Drupal.views.ajaxView.prototype.bind = function (settings) {
    const ajax = Drupal.ajax({ ...this.element_settings, ...settings });
    let target;
    const { beforeSend } = ajax.options;
    ajax.options.beforeSend = function (xhr, options) {
      target = document.querySelector(ajax.selector);
      const result = beforeSend.call(this, xhr, options);
      if (target && result !== false) {
        latestRequests.set(target, xhr);
      }
      ajax.request = xhr;
      return result;
    };
    const { success } = ajax;
    ajax.success = function (response, status) {
      if (
        !target?.isConnected ||
        latestRequests.get(target) !== this.request ||
        !this.element.isConnected
      ) {
        // Use the common cleanup without changing the current focus.
        const focus = Drupal.ajax.elementData.get(this.element, 'disable-refocus');
        const refocus = Drupal.ajax.elementData.get(this.element, 'refocus-blur');
        Drupal.ajax.elementData.set(this.element, 'disable-refocus', true);
        Drupal.ajax.elementData.set(this.element, 'refocus-blur', false);
        return success.call(this, [], status).finally(() => {
          Drupal.ajax.elementData.set(this.element, 'disable-refocus', focus);
          Drupal.ajax.elementData.set(this.element, 'refocus-blur', refocus);
        });
      }
      return success.call(this, response, status);
    };
    return ajax;
  };

  /**
   * Sets the browser URL ajax command.
   *
   * @param {Drupal.Ajax} [ajax]
   *   A {@link Drupal.ajax} object.
   * @param {object} response
   *   Ajax response.
   * @param {string} response.url
   *   URL to be set.
   */
  Drupal.AjaxCommands.prototype.setBrowserUrl = (ajax, response) => {
    // Do not change browser URL if we are in a dialog wrapper.
    if (ajax.element && !ajax.dialogOrigin && !ajax.element.closest('.drupal-dialog-content')) {
      window.history.replaceState(null, '', response.url);
    }
  };
})(Drupal, drupalSettings, once);
