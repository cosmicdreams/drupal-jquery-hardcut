/**
 * @file
 * Simulates extensions that still use the legacy Ajax override contracts.
 */

(($, Drupal) => {
  const trace = [];
  const originalBeforeSend = Drupal.Ajax.prototype.beforeSend;
  Drupal.Ajax.prototype.beforeSend = function (...args) {
    trace.push('beforeSend');
    return originalBeforeSend.apply(this, args);
  };
  $(document).on('ajaxComplete.legacyOverrides', () => {
    trace.push('ajaxComplete');
  });

  const originalEventResponse = Drupal.Ajax.prototype.eventResponse;
  Drupal.Ajax.prototype.eventResponse = function (...args) {
    if (
      this.legacyOverride &&
      this.$form[0].querySelector('select').value !== 'enabled'
    ) {
      return;
    }
    return originalEventResponse.apply(this, args);
  };

  Drupal.behaviors.legacyAjaxOverrides = {
    attach(context) {
      once('legacy-ajax-overrides', '#legacy-ajax-overrides', context).forEach(
        (form) => {
          const $form = $(form);
          const button = form.querySelector('button');
          const ajax = Drupal.ajax({
            url: form.action,
            element: button,
            event: 'mousedown.legacyOverrides',
            setClick: true,
            legacyOverride: true,
          });
          ajax.$form = $form;
          const success = ajax.success;
          ajax.success = function (response, status) {
            return Promise.resolve(success.call(this, response, status)).then(
              () => {
                trace.push('success');
                form.querySelector('select').focus();
              },
            );
          };

          window.legacyAjaxOverrides = { ajax, $form, trace };
        },
      );
    },
  };
})(jQuery, Drupal);
