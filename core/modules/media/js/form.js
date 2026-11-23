/**
 * @file
 * Defines JavaScript behaviors for the media form.
 */

(function (Drupal) {
  /**
   * Behaviors for summaries for tabs in the media edit form.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches summary behavior for tabs in the media edit form.
   */
  Drupal.behaviors.mediaFormSummaries = {
    attach(context) {
      Drupal.form.setSummary(
        context.querySelectorAll('.media-form-author'),
        (context) => {
          const nameInput = context.querySelector('.field--name-uid input');
          const name = nameInput?.value;
          const dateInput = context.querySelector('.field--name-created input');
          const date = dateInput?.value;

          if (name && date) {
            return Drupal.t('By @name on @date', {
              '@name': name,
              '@date': date,
            });
          }
          if (name) {
            return Drupal.t('By @name', { '@name': name });
          }
          if (date) {
            return Drupal.t('Authored on @date', { '@date': date });
          }
        },
      );
    },
  };
})(Drupal);
