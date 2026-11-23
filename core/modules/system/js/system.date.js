/**
 * @file
 * Provides date format preview feature.
 */

(function (Drupal, drupalSettings) {
  const dateFormats = drupalSettings.dateFormats;

  /**
   * Display the preview for date format entered.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attach behavior for previewing date formats on input elements.
   */
  Drupal.behaviors.dateFormat = {
    attach(context) {
      const source = once(
        'dateFormat',
        '[data-drupal-date-formatter="source"]',
        context,
      );
      const target = once(
        'dateFormat',
        '[data-drupal-date-formatter="preview"]',
        context,
      );

      // All elements have to exist.
      if (!source.length || !target.length) {
        return;
      }

      /**
       * Event handler that replaces date characters with value.
       *
       * @param {Event} e
       *   The native input event.
       */
      function dateFormatHandler(e) {
        const baseValue = e.target.value || '';
        const dateString = baseValue.replace(/\\?(.?)/gi, (key, value) =>
          dateFormats[key] ? dateFormats[key] : value,
        );

        // Set date preview.
        target.forEach((item) => {
          item.querySelectorAll('em').forEach((em) => {
            em.textContent = dateString;
          });
          item.classList.toggle('js-hide', !dateString.length);
        });
      }

      source.forEach((element) => {
        ['keyup', 'change', 'input'].forEach((type) => {
          Drupal.form.listen(element, type, dateFormatHandler);
        });
      });
      source.forEach((element) => {
        element.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true }));
      });
    },
  };
})(Drupal, drupalSettings);
