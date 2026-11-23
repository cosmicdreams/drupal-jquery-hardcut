/**
 * @file
 * Table select functionality.
 */

(function (Drupal, once) {
  /**
   * Initialize tableSelects.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches tableSelect functionality.
   */
  Drupal.behaviors.tableSelect = {
    attach(context) {
      if (!context?.querySelectorAll) {
        return;
      }
      // Select the inner-most table in case of nested tables.
      const tables = [
        ...new Set(
          [...context.querySelectorAll('th.select-all')]
            .map((header) => header.closest('table'))
            .filter(Boolean),
        ),
      ].sort((a, b) =>
        a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_PRECEDING
          ? 1
          : -1,
      );
      once('table-select', tables).forEach((table) =>
        Drupal.tableSelect.call(table),
      );
    },
  };

  const selectionChanges = new WeakSet();
  const { tableInteraction } = Drupal;

  /**
   * Sets a checkbox state and notifies listeners when it changes.
   *
   * @param {HTMLInputElement} checkbox
   *   The checkbox to update.
   * @param {boolean} state
   *   Its new checked state.
   */
  function setChecked(checkbox, state) {
    if (checkbox.checked !== state) {
      checkbox.checked = state;
      const event = new Event('change', { bubbles: true });
      selectionChanges.add(event);
      checkbox.dispatchEvent(event);
    }
  }

  /**
   * Callback used in {@link Drupal.behaviors.tableSelect}.
   */
  Drupal.tableSelect = function () {
    // Do not add a "Select all" checkbox if there are no rows with checkboxes
    // in the table.
    if (!this.querySelector('td input[type="checkbox"]')) {
      return;
    }

    // Keep track of the table, which checkbox is checked and alias the
    // settings.
    const table = this;
    let lastChecked;
    const strings = {
      selectAll: Drupal.t('Select all rows in this table'),
      selectNone: Drupal.t('Deselect all rows in this table'),
    };
    const updateSelectAll = function (state) {
      // Update table's select-all checkbox (and sticky header's if available).
      const previous = table.previousElementSibling;
      const tables = previous?.matches('table.sticky-header')
        ? [previous, table]
        : [table];
      const selectAll = tables.flatMap((element) =>
        Array.from(
          element.querySelectorAll('th.select-all input[type="checkbox"]'),
        ),
      );
      selectAll.forEach((checkbox) => {
        checkbox.title = state ? strings.selectNone : strings.selectAll;
        setChecked(checkbox, state);
      });
    };

    const checkboxes = [
      ...table.querySelectorAll('td input[type="checkbox"]:enabled'),
    ];
    const headers = [...table.querySelectorAll('th.select-all')];
    const missing = headers.filter(
      (header) => !header.querySelector('input[type="checkbox"]'),
    );
    if (missing.length) {
      tableInteraction.insertCheckboxes(
        missing,
        tableSelect.checkboxContent(),
        strings.selectAll,
      );
    }
    headers.forEach((header) => {
      tableInteraction.listen(table, header, 'click', (event) => {
        if (event.target.matches('input[type="checkbox"]')) {
          checkboxes.forEach((checkbox) => {
            setChecked(checkbox, event.target.checked);
            checkbox
              .closest('tr')
              ?.classList.toggle('selected', checkbox.checked);
          });
          updateSelectAll(event.target.checked);
        }
      });
    });
    tableInteraction.own(table, table, () =>
      once.remove('table-select', table),
    );

    checkboxes.forEach((checkbox) => {
      tableInteraction.listen(table, checkbox, 'click', function (e) {
        // Either add or remove the selected class based on the state of the
        // check all checkbox.

        /**
         * @this {HTMLElement}
         */
        this.closest('tr')?.classList.toggle('selected', this.checked);

        // If this is a shift click, we need to highlight everything in the
        // range. Also make sure that we are actually checking checkboxes
        // over a range and that a checkbox has been checked or unchecked before.
        if (e.shiftKey && lastChecked && lastChecked !== e.target) {
          // We use the checkbox's parent <tr> to do our range searching.
          Drupal.tableSelectRange(
            e.target.closest('tr'),
            lastChecked.closest('tr'),
            e.target.checked,
          );
        }

        // If all checkboxes are checked, make sure the select-all one is checked
        // too, otherwise keep unchecked.
        updateSelectAll(checkboxes.every((checkbox) => checkbox.checked));

        // Keep track of the last checked checkbox.
        lastChecked = e.target;
      });
    });

    // If all checkboxes are checked on page load, make sure the select-all one
    // is checked too, otherwise keep unchecked.
    updateSelectAll(checkboxes.every((checkbox) => checkbox.checked));
  };

  /**
   * @param {HTMLElement} from
   *   The HTML element representing the "from" part of the range.
   * @param {HTMLElement|string} to
   *   The HTML element representing the "to" part of the range.
   * @param {boolean} state
   *   The state to set on the range.
   */
  Drupal.tableSelectRange = function (from, to, state) {
    const direction =
      from.rowIndex > to.rowIndex
        ? 'previousElementSibling'
        : 'nextElementSibling';

    for (let row = from[direction]; row; row = row[direction]) {
      if (typeof state === 'boolean') {
        row.classList.toggle('selected', state);
        row.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
          checkbox.checked = state;
        });
      }
      if (row === to || (typeof to === 'string' && row.matches(to))) {
        break;
      }
    }
  };

  const tableSelect = Drupal.tableSelect;

  /**
   * Identifies a change dispatched by table select while updating other inputs.
   *
   * @param {Event} event
   *   The event to check.
   * @return {boolean}
   *   Whether table select dispatched the event.
   *
   * @internal
   */
  tableSelect.isSelectionChange = (event) => selectionChanges.has(event);
  tableSelect.checkboxContent = () => Drupal.theme('checkbox');
  tableInteraction.tableSelect = tableSelect;
})(Drupal, once);
