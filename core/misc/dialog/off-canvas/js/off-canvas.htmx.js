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
  let latest;

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
      requests.set(ctx, { source, request: ctx.request, position });
    },

    htmx_before_request(elt, { ctx }) {
      const request = requests.get(ctx);
      if (request) {
        latest?.request.abort();
        latest = request;
      }
    },

    htmx_after_request(elt, { ctx }) {
      const request = requests.get(ctx);
      if (request) {
        // A response may wait on assets even after a newer request aborts it.
        if (request !== latest || !request.source.isConnected) {
          return false;
        }
        // Resolve the tray after loading. The user may have closed or
        // replaced it since the request started.
        ctx.target = getTarget();
      }
      return true;
    },

    htmx_before_swap(elt, { ctx, tasks }) {
      const request = requests.get(ctx);
      if (request) {
        const task = tasks.find(({ type }) => type === 'main');
        if (task) {
          // The response title belongs in the tray, not in the document head.
          swaps.set(task, { request, title: ctx.title });
        }
        ctx.title = null;
      }
    },

    htmx_after_settle(elt, { task }) {
      const swap = swaps.get(task);
      if (!swap || swap.request !== latest || !task.target.isConnected) {
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
      }).show();
    },
  });
})(Drupal, htmx);
