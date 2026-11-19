/**
 * @file
 * Attaches comment behaviors to the entity form.
 */

(function ($, Drupal) {
  /**
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.commentFieldsetSummaries = {
    attach(context) {
      $(context)
        .find('fieldset.comment-entity-settings-form')
        .drupalSetSummary((fieldset) => {
          const label = Array.from(
            fieldset.querySelectorAll('.js-form-item-comment input:checked'),
          )
            .map((input) => input.nextElementSibling)
            .find((element) => element?.matches('label'));
          return Drupal.checkPlain(label.textContent);
        });
    },
  };
})(jQuery, Drupal);
