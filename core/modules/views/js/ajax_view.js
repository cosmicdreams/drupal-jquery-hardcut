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
    detach(context, settings, trigger) {
      const ajaxViews = settings?.views?.ajaxViews;
      if (trigger !== 'unload' || !ajaxViews) {
        return;
      }
      Object.entries(ajaxViews).forEach(([key, view]) => {
        const selector = `.js-view-dom-id-${view.view_dom_id}`;
        if (context.matches?.(selector) || context.querySelector(selector)) {
          // A refresh synchronously replaces the view with the same DOM ID.
          queueMicrotask(() => {
            if (ajaxViews[key] === view && !document.querySelector(selector)) {
              delete ajaxViews[key];
            }
          });
        }
      });
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
    const ownElements = (selector) =>
      [...view.querySelectorAll(selector)].filter(
        (element) =>
          element.closest('.view, [class*="js-view-dom-id-"]') === view,
      );
    const inlineForms = ownElements(formSelector);
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
      // Attachments have no view DOM ID and refresh their outer view.
      const links = [
        ...view.querySelectorAll(
          '.js-pager__items a[href], th.views-field a[href], .attachment .views-summary a[href]',
        ),
      ].filter((link) => link.closest('[class*="js-view-dom-id-"]') === view);
      once('views-pager', links).forEach((element) => {
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
   * Guards a view or preview refresh until its response commands finish.
   * @param {Drupal.Ajax} ajax
   *   The request whose commands update the view.
   * @param {string} selector
   *   The view or preview wrapper.
   */
  Drupal.Views.trackRefreshResponse = (ajax, selector) => {
    const original = document.querySelector(selector);
    const request = Symbol();
    let target = original;
    if (target) {
      latestRequests.set(target, request);
    }

    ajax.isResponseCurrent = () =>
      !!target?.isConnected &&
      latestRequests.get(target) === request &&
      (target !== original || !!ajax.element?.isConnected) &&
      (Drupal.dialog?.hasOpenOrigin(ajax.dialogOrigin) ?? true);

    ajax.commands.insert = (instance, response, status) => {
      const inserted = Drupal.AjaxCommands.prototype.insert(
        instance,
        response,
        status,
      );
      if (target?.isConnected) {
        return;
      }
      // Continue with trailing commands on the replacement this request wrote.
      // Its newly attached behaviors may already have started another request.
      target = inserted
        ?.flatMap((element) => [element, ...element.querySelectorAll(selector)])
        .find((element) => element.isConnected && element.matches(selector));
      if (target && !latestRequests.has(target)) {
        latestRequests.set(target, request);
      }
    };
  };

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
    const { beforeSend } = ajax;
    ajax.beforeSend = function (xhr, options) {
      const result = beforeSend.call(this, xhr, options);
      if (result !== false) {
        Drupal.Views.trackRefreshResponse(this, this.selector);
      }
      return result;
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
