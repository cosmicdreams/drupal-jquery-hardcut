/**
 * @file
 * Attaches administration-specific behavior for the Filter module.
 */

((Drupal) => {
  /**
   * Displays and updates the status of filters on the admin page.
   */
  Drupal.behaviors.filterStatus = {
    attach(context) {
      once(
        'filter-status',
        '#filters-status-wrapper input.form-checkbox',
        context,
      ).forEach((checkbox) => {
        const row = context
          .querySelector(
            `#${CSS.escape(checkbox.id.replace(/-status$/, '-weight'))}`,
          )
          ?.closest('tr');
        const settingsSelector = checkbox.id.replace(/-status$/, '-settings');
        const pane = context.querySelector(
          `[data-drupal-selector="${CSS.escape(settingsSelector)}"]`,
        );
        const tab = pane && Drupal.verticalTabs?.get(pane);

        const update = () => {
          const display = checkbox.checked ? '' : 'none';
          if (row) {
            row.style.display = display;
          }
          if (tab) {
            const activeTab = checkbox.checked ? tab.tabShow() : tab.tabHide();
            activeTab.updateSummary();
          } else if (pane) {
            // On narrow viewports the settings are ordinary details elements.
            pane.style.display = display;
          }
          Drupal.tableDrag['filter-order'].restripeTable();
        };

        Drupal.form.listen(checkbox, 'click', 'filterUpdate', update);
        if (tab) {
          Drupal.form.setSummary(pane, () =>
            checkbox.checked ? Drupal.t('Enabled') : Drupal.t('Disabled'),
          );
        }
        update();
      });
    },
  };
})(Drupal);
