/**
 * @file
 * Opens native off-canvas dialogs with htmx response fragments.
 */

((Drupal, htmx) => {
  const positions = new Map([
    ['off_canvas', 'side'],
    ['off_canvas_top', 'top'],
  ]);
  const requests = new WeakMap();
  const swaps = new WeakMap();
  const selector = '#drupal-off-canvas';

  function canSwap({ source, origin, generation }) {
    return (
      source.isConnected &&
      Drupal.dialog.hasOpenOrigin(origin) &&
      Drupal.dialog.isLatestOpen(selector, generation)
    );
  }

  function getTarget() {
    let target = document.getElementById('drupal-off-canvas');
    if (!target) {
      // Keep the swap away from the body, where the asset extension would
      // detach behaviors for the entire page.
      let staging = document.querySelector('[data-drupal-off-canvas-htmx]');
      if (!staging) {
        staging = document.createElement('div');
        staging.hidden = true;
        staging.dataset.drupalOffCanvasHtmx = '';
        document.body.append(staging);
      }
      target = document.createElement('div');
      target.id = 'drupal-off-canvas';
      staging.append(target);
    }
    return target;
  }

  htmx.registerExtension('drupal-off-canvas', {
    htmx_config_request(elt, { ctx }) {
      const source = ctx.sourceElement;
      const position = positions.get(source?.dataset.dialogRenderer);
      const url = new URL(ctx.request.action, document.location.href);
      if (!position || !Drupal.url.isLocal(url.toString())) {
        return;
      }

      url.searchParams.set('_wrapper_format', 'drupal_htmx');
      ctx.request.action = url.pathname + url.search;
      ctx.swap = 'innerHTML';
      ctx.request.headers['HX-Target'] = 'div#drupal-off-canvas';
      requests.set(ctx, { source, position });
    },

    htmx_before_request(elt, { ctx }) {
      const request = requests.get(ctx);
      if (request) {
        request.generation = Drupal.dialog.beginOpenRequest(selector);
        request.origin = Drupal.dialog.requestOrigin(request.source);
      }
    },

    htmx_before_response(elt, { ctx }) {
      const request = requests.get(ctx);
      if (!request) {
        return true;
      }
      const { status, ok } = ctx.response.raw;
      if (!canSwap(request)) {
        delete ctx.hx.trigger;
        return false;
      }
      if (!ok) {
        delete ctx.hx.trigger;
        new Drupal.Message().add(
          Drupal.t(
            "Oops, something went wrong. Check your browser's developer console for more details.",
          ),
          { type: 'error' },
        );
        console.error(
          `htmx off-canvas request failed (${status}): ${ctx.request.action}`,
        );
        return false;
      }
      return true;
    },

    htmx_after_request(elt, { ctx }) {
      const request = requests.get(ctx);
      if (request) {
        // Loading response assets can outlast a more recent open.
        if (!canSwap(request)) {
          delete ctx.hx.trigger;
          return false;
        }
        // Resolve the tray after loading. An external opener can still open
        // a tray after the user closes the one currently displayed.
        ctx.target = getTarget();
      }
      return true;
    },

    htmx_before_swap(elt, { ctx, tasks }) {
      const request = requests.get(ctx);
      if (!request) {
        return true;
      }
      if (!canSwap(request)) {
        delete ctx.hx.trigger;
        return false;
      }
      const task = tasks.find(({ type }) => type === 'main');
      if (task) {
        // Keep the checked swap synchronous with respect to a new open.
        Object.assign(task.swapSpec, { transition: false, swap: 0, settle: 0 });
        swaps.set(task, { request, title: ctx.title });
      }
      // The response title belongs in the tray, not in the document head.
      ctx.title = null;
      return true;
    },

    htmx_finally_request(elt, { ctx }) {
      if (requests.get(ctx)?.generation !== undefined) {
        Drupal.dialog.finishOpenRequest();
      }
    },

    htmx_after_settle(elt, { task }) {
      const swap = swaps.get(task);
      if (
        !swap ||
        !task.target.isConnected ||
        !Drupal.dialog.isLatestOpen(selector, swap.request.generation)
      ) {
        return;
      }
      const { request, title } = swap;
      const content = task.target;
      const previous = Drupal.dialog.get(content);
      if (previous?.open) {
        // htmx owns this content element and has already attached behaviors.
        previous.settings.removeOnClose = false;
        previous.close();
      }
      if (!Drupal.dialog.isLatestOpen(selector, request.generation)) {
        return;
      }
      const options = JSON.parse(request.source.dataset.dialogOptions || '{}');
      const { position } = request;
      Drupal.dialog(content, {
        width: 300,
        height: undefined,
        ...options,
        classes: {
          'drupal-dialog': [
            'drupal-dialog-off-canvas',
            'drupal-dialog-position-' + position,
            options.classes?.['drupal-dialog'],
          ]
            .filter(Boolean)
            .join(' '),
          'drupal-dialog-content': 'drupal-off-canvas-reset',
        },
        title,
        opener: request.source,
        drupalAutoButtons: false,
        drupalOffCanvasPosition: position,
        removeOnClose: true,
      }).show(request.generation);
    },
  });
})(Drupal, htmx);
