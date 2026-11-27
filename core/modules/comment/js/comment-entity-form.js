/**
 * @file
 * Attaches comment behaviors to the entity form.
 */

(function (Drupal) {
  /**
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.commentFieldsetSummaries = {
    attach(context) {
      Drupal.form.setSummary(
        context.querySelectorAll('fieldset.comment-entity-settings-form'),
        (fieldset) => {
          const label = Array.from(
            fieldset.querySelectorAll('.js-form-item-comment input:checked'),
          )
            .map((input) => input.nextElementSibling)
            .find((element) => element?.matches('label'));
          return Drupal.checkPlain(label.textContent);
        },
      );
    },
  };
})(Drupal);
