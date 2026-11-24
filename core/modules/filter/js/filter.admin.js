/**
 * @file
 * Attaches administration-specific behavior for the Filter module.
 */

((Drupal, once) => {
  const controls = new WeakMap();
  const formSelector =
    '[data-drupal-selector="filter-format-edit-form"], [data-drupal-selector="filter-format-add-form"]';

  function sync(form, checkbox, changed) {
    const state = controls.get(checkbox);
    const id = checkbox.dataset.drupalSelector || checkbox.id;
    const weight = CSS.escape(id.replace(/-status$/, '-weight'));
    const row = form
      .querySelector(`[data-drupal-selector="${weight}"]`)
      ?.closest('tr');
    const settingsSelector = id.replace(/-status$/, '-settings');
    const pane = form.querySelector(
      `[data-drupal-selector="${CSS.escape(settingsSelector)}"]`,
    );
    const tab = pane && Drupal.verticalTabs?.get(pane);
    if (changed || state.row !== row) {
      if (row) {
        row.style.display = checkbox.checked ? '' : 'none';
        Drupal.tableDrag?.[row.closest('table').id]?.restripeTable();
      }
    }
    if (changed || state.pane !== pane || state.tab !== tab) {
      if (tab) {
        if (state.tab !== tab) {
          Drupal.form.setSummary(pane, () =>
            checkbox.checked ? Drupal.t('Enabled') : Drupal.t('Disabled'),
          );
        }
        const activeTab = checkbox.checked ? tab.tabShow() : tab.tabHide();
        activeTab.updateSummary();
      } else if (pane) {
        // On narrow viewports the settings are ordinary details elements.
        pane.style.display = checkbox.checked ? '' : 'none';
      }
    }
    Object.assign(state, { row, pane, tab });
  }

  /**
   * Displays and updates the status of filters on the admin page.
   */
  Drupal.behaviors.filterStatus = {
    attach(context) {
      const parent = context.closest?.(formSelector);
      const forms = parent ? [parent] : context.querySelectorAll(formSelector);
      forms.forEach((form) => {
        form
          .querySelectorAll('#filters-status-wrapper input.form-checkbox')
          .forEach((checkbox) => {
            if (once('filter-status', checkbox).length) {
              const stop = Drupal.form.listen(checkbox, 'click', () =>
                sync(form, checkbox, true),
              );
              controls.set(checkbox, { stop });
            }
            sync(form, checkbox, false);
          });
      });
    },
    detach(context, settings, trigger) {
      if (trigger !== 'unload') {
        return;
      }
      const selector = '#filters-status-wrapper input.form-checkbox';
      const candidates = context.matches?.(selector)
        ? context
        : context.querySelectorAll(selector);
      once.remove('filter-status', candidates).forEach((checkbox) => {
        controls.get(checkbox).stop();
        controls.delete(checkbox);
      });
    },
  };
})(Drupal, once);
