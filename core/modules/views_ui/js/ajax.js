/**
 * @file
 * Handles Ajax submission and responses in Views UI.
 */

((Drupal, once) => {
  const commands = Drupal.AjaxCommands.prototype;

  function isCurrentDialogResponse(ajax) {
    return (
      Drupal.dialog.hasOpenOrigin(ajax.dialogOrigin) &&
      Drupal.dialog.isLatestOpen('#drupal-modal', ajax.dialogGeneration)
    );
  }

  commands.viewsHighlight = (ajax, response) => {
    if (!isCurrentDialogResponse(ajax)) {
      return;
    }
    document
      .querySelectorAll('.hilited')
      .forEach((element) => element.classList.remove('hilited'));
    document
      .querySelectorAll(response.selector)
      .forEach((element) => element.classList.add('hilited'));
  };

  commands.viewsSetForm = (ajax, response) => {
    if (!isCurrentDialogResponse(ajax)) {
      return;
    }
    once('views-ajax-submit', '.js-views-ui-dialog[open] form').forEach(
      (form) => {
        Drupal.ajax({
          url: response.url,
          event: 'submit',
          base: form.id,
          element: form,
        }).form = form;
      },
    );
  };

  commands.viewsShowButtons = (ajax, response) => {
    document
      .querySelectorAll('.views-edit-view .form-actions')
      .forEach((element) => element.classList.remove('js-hide'));
    if (response.changed) {
      document
        .querySelectorAll('.views-edit-view .view-changed.messages')
        .forEach((element) => element.classList.remove('js-hide'));
    }
  };

  let previewQueued = false;
  function updatePreview() {
    if (!document.getElementById('edit-displays-live-preview')?.checked) {
      return;
    }
    if (
      [...Drupal.ajax.pendingRequests()].some(
        ({ wrapper }) => wrapper === '#views-preview-wrapper',
      )
    ) {
      previewQueued = true;
      return;
    }
    previewQueued = false;
    document.getElementById('preview-submit')?.click();
  }

  commands.viewsTriggerPreview = updatePreview;

  document.addEventListener('drupal:ajax-complete', ({ detail: { ajax } }) => {
    if (previewQueued && ajax.wrapper === '#views-preview-wrapper') {
      previewQueued = false;
      updatePreview();
    }
  });

  commands.viewsReplaceTitle = (ajax, response) => {
    // Preserve the separator used by the theme, if the title ends in the site name.
    const escapedSiteName = response.siteName.replace(
      /[-[\]{}()*+?.,\\^$|#\s]/g,
      '\\$&',
    );
    const pattern = new RegExp(`.+ (.) ${escapedSiteName}$`);
    document.title = document.title.replace(
      pattern,
      (match, separator) =>
        `${response.title} ${separator} ${response.siteName}`,
    );
    document.querySelectorAll('h1.page-title').forEach((heading) => {
      heading.textContent = response.title;
    });
  };

  // The Views editor shows its own save notice.
  Drupal.theme.tableDragChangedWarning = () => [];

  Drupal.behaviors.viewsAjax = {
    attach(context) {
      const baseSettings = {
        event: 'click',
        progress: { type: 'fullscreen' },
      };
      once('views-ajax', 'a.views-ajax-link[href]', context).forEach(
        (element) => {
          Drupal.ajax({
            ...baseSettings,
            element,
            base: element.id,
            url: element.getAttribute('href'),
          });
        },
      );

      once(
        'views-ajax',
        '#views-live-preview a[href], #views-live-preview input[type="submit"], #views-live-preview button[type="submit"]',
        context,
      ).forEach((element) => {
        const url =
          element.getAttribute('href') ?? element.form?.getAttribute('action');
        if (
          url &&
          Drupal.Views.getPath(url).startsWith('admin/structure/views/')
        ) {
          Drupal.ajax({
            ...baseSettings,
            element,
            base: element.id,
            url,
            setClick: !!element.form,
            wrapper: 'views-preview-wrapper',
            method: 'replaceWith',
          });
        }
      });

      once(
        'views-live-preview',
        '#edit-displays-live-preview',
        context,
      ).forEach((checkbox) => {
        checkbox.addEventListener('change', updatePreview);
        // Preview responses include the checkbox again. Start only on page load.
        if (context === document) {
          updatePreview();
        }
      });
    },
  };
})(Drupal, once);
