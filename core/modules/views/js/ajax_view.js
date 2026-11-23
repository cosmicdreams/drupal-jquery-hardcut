/**
 * @file
 * Handles AJAX fetching of views, including filter submission and response.
 */

((Drupal, drupalSettings) => {
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
    if (settings?.views?.ajaxViews) {
      const {
        views: { ajaxViews },
      } = settings;
      Object.keys(ajaxViews || {}).forEach((i) => {
        Drupal.views.instances[i] = new Drupal.views.ajaxView(ajaxViews[i]);
      });
    }
  };
  Drupal.behaviors.ViewsAjaxView.detach = (context, settings, trigger) => {
    if (trigger === 'unload') {
      if (settings?.views?.ajaxViews) {
        const {
          views: { ajaxViews },
        } = settings;
        Object.keys(ajaxViews || {}).forEach((i) => {
          const selector = `.js-view-dom-id-${ajaxViews[i].view_dom_id}`;
          if (context.querySelector(selector)) {
            delete Drupal.views.instances[i];
            delete settings.views.ajaxViews[i];
          }
        });
      }
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
    this.views = [...document.querySelectorAll(selector)];
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

    // Check if there are any GET parameters to send to views.
    let queryString = window.location.search || '';
    if (queryString !== '') {
      // Remove the question mark and Drupal path component if any.
      queryString = queryString.slice(1);

      // Collect the base names of every named field in the exposed form. The
      // trailing `[…]` is stripped so a multi-select field named `type[]`
      // matches URL params serialized by PHP as `type[0]`, `type[1]`, or
      // `type[]`.
      const stripArraySuffix = (name) => name.replace(/\[.*$/, '');
      const exposedFieldBaseNames = new Set(
        this.exposedForms
          .flatMap((form) => [...form.querySelectorAll('[name]')])
          .map((element) => stripArraySuffix(element.name)),
      );

      // Remove current exposed filters.
      const params = decodeURI(queryString)
        .split('&')
        .filter((param) => {
          const [name] = param.split('=');
          return (
            !exposedFieldBaseNames.has(stripArraySuffix(name)) &&
            /*
            Submitting filters should reset paging and sorting
            because that is what happens without AJAX.
             */
            !['page', 'reset', 'sort', 'order', 'q', 'render'].includes(name)
          );
        });
      queryString = encodeURI(params.join('&'));
      // If there is a '?' in ajaxPath, clean URL are on and & should be
      // used to add parameters.
      if (queryString !== '') {
        queryString = (ajaxPath.includes('?') ? '&' : '?') + queryString;
      }
    }

    this.element_settings = {
      url: ajaxPath + queryString,
      submit: settings,
      httpMethod: 'GET',
      setClick: true,
      event: 'click',
      selector,
      progress: { type: 'fullscreen' },
    };

    this.settings = settings;

    // Add the ajax to exposed forms.
    once('exposed-form', this.exposedForms).forEach(
      this.attachExposedFormAjax.bind(this),
    );

    // Add the ajax to pagers.
    once(
      'ajax-pager',
      this.views
        // Don't attach to nested views. Doing so would attach multiple behaviors
        // to a given element.
        .filter(this.filterNestedViews.bind(this)),
    ).forEach(this.attachPagerAjax.bind(this));

    // Add a trigger to update this view specifically. In order to trigger a
    // refresh use the following code.
    //
    // @code
    // document.querySelector('.view-name').dispatchEvent(new Event('RefreshView'));
    // @endcode
    const selfSettings = {
      ...this.element_settings,
      event: 'RefreshView',
      base: this.selector,
      httpMethod: 'GET',
      element: this.views[0],
    };
    this.refreshViewAjax = Drupal.ajax(selfSettings);
  };

  /**
   * @method
   */
  Drupal.views.ajaxView.prototype.attachExposedFormAjax = function () {
    // Exclude reset buttons so AJAX does not interfere with form reset.
    const selector =
      ':is(input[type=submit], button[type=submit], input[type=image]):not([data-drupal-selector=edit-reset])';
    this.exposedFormAjax = this.exposedForms
      .flatMap((form) => [...form.querySelectorAll(selector)])
      .map((element) =>
        Drupal.ajax({
          ...this.element_settings,
          base: element.id,
          element,
        }),
      );
  };

  /**
   * @return {boolean}
   *   If there is at least one parent with a view class return false.
   */
  Drupal.views.ajaxView.prototype.filterNestedViews = function () {
    // If there is at least one parent with a view class, this view
    // is nested (e.g., an attachment). Bail.
    return this.views.every((view) => !view.parentElement?.closest('.view'));
  };

  /**
   * Attach the ajax behavior to each link.
   */
  Drupal.views.ajaxView.prototype.attachPagerAjax = function () {
    const selector =
      '.js-pager__items a, th.views-field a, .attachment .views-summary a';
    const links = new Set(
      this.views.flatMap((view) => [...view.querySelectorAll(selector)]),
    );
    [...links].forEach((link, index) => this.attachPagerLinkAjax(index, link));
  };

  /**
   * Attach the ajax behavior to a singe link.
   *
   * @param {string} [id]
   *   The ID of the link.
   * @param {HTMLElement} link
   *   The link element.
   */
  Drupal.views.ajaxView.prototype.attachPagerLinkAjax = function (id, link) {
    const href = link.getAttribute('href');
    const viewData = {
      ...this.settings,
      ...Drupal.Views.parseQueryString(href),
      ...Drupal.Views.parseViewArgs(href, this.settings.view_base_path),
    };
    const selfSettings = {
      ...this.element_settings,
      submit: viewData,
      base: false,
      element: link,
      httpMethod: 'GET',
    };
    this.pagerAjax = Drupal.ajax(selfSettings);
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
    if (ajax.element && !ajax.element.closest('.ui-dialog-content')) {
      window.history.replaceState(null, '', response.url);
    }
  };
})(Drupal, drupalSettings);
