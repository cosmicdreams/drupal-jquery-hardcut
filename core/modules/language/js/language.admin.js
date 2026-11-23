/**
 * @file
 * Language admin behavior.
 */

((Drupal) => {
  /**
   * Makes language negotiation inherit user interface negotiation.
   */
  Drupal.behaviors.negotiationLanguage = {
    attach() {
      const form = document.getElementById(
        'language-negotiation-configure-form',
      );
      if (!form) {
        return;
      }
      const inputSelector = 'input[name$="[configurable]"]';
      const toggleTable = (checkbox) => {
        const group = checkbox.closest('.table-language-group');
        group
          ?.querySelectorAll('table, .tabledrag-toggle-weight')
          .forEach((element) => {
            element.style.display = checkbox.checked ? '' : 'none';
          });
      };
      once('negotiation-language-admin-bind', form).forEach((element) => {
        Drupal.formEvents.listen(
          element,
          'change',
          (event, checkbox) => toggleTable(checkbox),
          inputSelector,
        );
      });
      form
        .querySelectorAll(`${inputSelector}:not(:checked)`)
        .forEach(toggleTable);
    },
  };
})(Drupal);
