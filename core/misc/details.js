/**
 * @file
 * Additional functionality for HTML5 details elements.
 */

((Drupal) => {
  /**
   * Opens closed parent details elements so the target becomes visible.
   *
   * @param {HTMLElement|null} target
   *   The fragment target or invalid form field.
   */
  function openParentDetails(target) {
    const parents = [];
    for (
      let parent = target?.parentElement;
      parent;
      parent = parent.parentElement
    ) {
      if (parent.matches('details:not([open])')) {
        parents.push(parent);
      }
    }
    parents.reverse().forEach((parent) => {
      parent
        .querySelectorAll(':scope > summary')
        .forEach((summary) => summary.click());
    });
  }

  Drupal.form.listen(
    document.body,
    'formFragmentLinkClickOrHashChange',
    (event) => openParentDetails(event.detail),
  );

  window.addEventListener(
    'invalid',
    (event) => {
      if (
        event.target.matches('details :is(input, textarea, select)[required]')
      ) {
        openParentDetails(event.target);
      }
    },
    { capture: true },
  );
})(Drupal);
