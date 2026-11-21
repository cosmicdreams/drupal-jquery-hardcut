/**
 * @file
 * Updates the available menu parents.
 */

((Drupal) => {
  Drupal.behaviors.menuUiChangeParentItems = {
    attach() {
      once('menu-parent', '#edit-menu').forEach((menu) => {
        Drupal.menuUiUpdateParentList();
        Drupal.formEvents.listen(
          menu,
          'change',
          undefined,
          Drupal.menuUiUpdateParentList,
          'input',
        );
      });
    },
  };

  /**
   * Fetches the parents in the checked menus and preserves the selection.
   */
  Drupal.menuUiUpdateParentList = function () {
    const body = new URLSearchParams();
    document.querySelectorAll('#edit-menu input:checked').forEach((input) => {
      body.append('menus[]', Drupal.checkPlain(input.value));
    });
    Drupal.ajaxRequest(
      {
        url: Drupal.url('admin/structure/menu/parents'),
        method: 'POST',
        body,
        headers: { Accept: 'application/json, text/javascript, */*; q=0.01' },
      },
      ({ status, response }) => {
        const select = document.getElementById('edit-menu-parent');
        if (status !== 'success' || !select) {
          return;
        }
        const selected = select.value;
        const options = Object.entries(response || {}).map(
          ([value, title]) =>
            new Option(title, value, false, value === selected),
        );
        select.replaceChildren(...options);
        const wrapper = select.closest('div');
        if (wrapper) {
          wrapper.style.display = options.length ? '' : 'none';
          wrapper.hidden = !options.length;
        }
      },
    );
  };
})(Drupal);
