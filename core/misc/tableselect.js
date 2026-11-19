/**
 * @file
 * Table select functionality.
 */

(function ($, Drupal) {
  /**
   * Initialize tableSelects.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches tableSelect functionality.
   */
  Drupal.behaviors.tableSelect = {
    attach(context, settings) {
      // Select the inner-most table in case of nested tables.
      const tables =
        context && (context.nodeType === 1 || context.nodeType === 9)
          ? Array.from(
              new Set(
                Array.from(
                  context.querySelectorAll('th.select-all'),
                  (header) => header.closest('table'),
                ).filter(Boolean),
              ),
            ).sort((a, b) =>
              a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_PRECEDING
                ? 1
                : -1,
            )
          : $(context).find('th.select-all').closest('table');
      once('table-select', tables).forEach((table) =>
        Drupal.tableSelect.call(table),
      );
    },
  };

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
    let checkboxes;
    let lastChecked;
    const $table = $(table);
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
        const stateChanged = checkbox.checked !== state;

        checkbox.setAttribute(
          'title',
          state ? strings.selectNone : strings.selectAll,
        );

        /**
         * @checkbox {HTMLElement}
         */
        if (stateChanged) {
          checkbox.checked = state;
          $(checkbox).trigger('change');
        }
      });
    };

    // Find all <th> with class select-all, and insert the check all checkbox.
    $table
      .find('th.select-all')
      .prepend($(Drupal.theme('checkbox')).attr('title', strings.selectAll))
      .on('click', (event) => {
        if (event.target.matches('input[type="checkbox"]')) {
          // Loop through all checkboxes and set their state to the select all
          // checkbox' state.
          checkboxes.each(function () {
            const stateChanged = this.checked !== event.target.checked;

            /**
             * @checkbox {HTMLElement}
             */
            if (stateChanged) {
              this.checked = event.target.checked;
              $(this).trigger('change');
            }
            // Either add or remove the selected class based on the state of the
            // check all checkbox.

            /**
             * @checkbox {HTMLElement}
             */
            this.closest('tr')?.classList.toggle('selected', this.checked);
          });
          // Update the title and the state of the check all box.
          updateSelectAll(event.target.checked);
        }
      });

    // For each of the checkboxes within the table that are not disabled.
    checkboxes = $table
      .find('td input[type="checkbox"]:enabled')
      .on('click', function (e) {
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
            $(e.target).closest('tr')[0],
            $(lastChecked).closest('tr')[0],
            e.target.checked,
          );
        }

        // If all checkboxes are checked, make sure the select-all one is checked
        // too, otherwise keep unchecked.
        updateSelectAll(
          Array.from(checkboxes).every((checkbox) => checkbox.checked),
        );

        // Keep track of the last checked checkbox.
        lastChecked = e.target;
      });

    // If all checkboxes are checked on page load, make sure the select-all one
    // is checked too, otherwise keep unchecked.
    updateSelectAll(
      Array.from(checkboxes).every((checkbox) => checkbox.checked),
    );
  };

  /**
   * @param {HTMLElement} from
   *   The HTML element representing the "from" part of the range.
   * @param {HTMLElement} to
   *   The HTML element representing the "to" part of the range.
   * @param {boolean} state
   *   The state to set on the range.
   */
  Drupal.tableSelectRange = function (from, to, state) {
    // We determine the looping mode based on the order of from and to.
    const mode =
      from.rowIndex > to.rowIndex ? 'previousSibling' : 'nextSibling';

    // Traverse through the sibling nodes.
    for (let i = from[mode]; i; i = i[mode]) {
      // Make sure that we're only dealing with elements.
      if (i.nodeType !== Node.ELEMENT_NODE) {
        continue;
      }
      // Either add or remove the selected class based on the state of the
      // target checkbox.
      if (to.nodeType && typeof state === 'boolean') {
        i.classList.toggle('selected', state);
        i.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
          checkbox.checked = state;
        });
      } else {
        const $i = $(i);
        $i.toggleClass('selected', state);
        $i.find('input[type="checkbox"]').prop('checked', state);
      }

      if (to.nodeType) {
        // If we are at the end of the range, stop.
        if (i === to) {
          break;
        }
      }
      // A faster alternative to doing $(i).filter(to).length.
      else if ($.filter(to, [i]).r.length) {
        break;
      }
    }
  };
})(jQuery, Drupal);
