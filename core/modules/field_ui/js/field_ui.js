/**
 * @file
 * Attaches the behaviors for the Field UI module.
 */

(function (Drupal, debounce, once) {
  const rowHandlersByElement = new WeakMap();

  /**
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Adds behaviors to the field storage add form.
   */
  Drupal.behaviors.fieldUIFieldStorageAddForm = {
    attach(context) {
      const [form] = once(
        'field_ui_add',
        '[data-drupal-selector="field-ui-field-storage-add-form"]',
        context,
      );
      if (form) {
        // Add a few 'js-form-required' and 'form-required' css classes here.
        // We can not use the Form API '#required' property because both label
        // elements for "add new" and "re-use existing" can never be filled and
        // submitted at the same time. The actual validation will happen
        // server-side.
        form
          .querySelectorAll(
            '.js-form-item-label label,' +
              '.js-form-item-field-name label,' +
              '.js-form-item-existing-storage-label label',
          )
          .forEach((label) =>
            label.classList.add('js-form-required', 'form-required'),
          );
      }
    },
  };

  /**
   * Attaches the fieldUIOverview behavior.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches the fieldUIOverview behavior.
   *
   * @see Drupal.fieldUIOverview.attach
   */
  Drupal.behaviors.fieldUIDisplayOverview = {
    attach(context, settings) {
      once(
        'field-display-overview',
        'table#field-display-overview',
        context,
      ).forEach((overview) => {
        Drupal.fieldUIOverview.attach(
          overview,
          settings.fieldUIRowsData,
          Drupal.fieldUIDisplayOverview,
        );
      });
    },
  };

  // Storage changes can replace the configuration form. Keep its submit
  // button disabled through the command queue, including overlapping requests.
  const pendingRequests = new WeakMap();
  const requestOwners = new WeakMap();
  const submitSelector =
    '.field-config-edit-form [data-drupal-selector="edit-submit"]';

  document.addEventListener(
    'drupal:ajax-before-send',
    ({ detail: { ajax } }) => {
      const form = ajax.element?.closest('.field-config-edit-form');
      if (form && ajax.wrapper === '#field-combined') {
        const owner = form.closest('dialog') || document;
        pendingRequests.set(owner, (pendingRequests.get(owner) ?? 0) + 1);
        requestOwners.set(ajax, owner);
        const button = form.querySelector(
          '[data-drupal-selector="edit-submit"]',
        );
        if (button) {
          button.disabled = true;
        }
      }
    },
  );
  document.addEventListener('drupal:ajax-complete', ({ detail: { ajax } }) => {
    const owner = requestOwners.get(ajax);
    if (owner) {
      requestOwners.delete(ajax);
      const count = pendingRequests.get(owner) - 1;
      pendingRequests.set(owner, count);
      const button = owner.querySelector(submitSelector);
      if (button) {
        button.disabled = count > 0;
      }
    }
  });

  Drupal.behaviors.fieldUICombinedForm = {
    attach(context) {
      context.querySelectorAll(submitSelector).forEach((button) => {
        if (pendingRequests.get(button.closest('dialog') || document)) {
          button.disabled = true;
        }
      });
    },
  };

  /**
   * Namespace for the field UI overview.
   *
   * @namespace
   */
  Drupal.fieldUIOverview = {
    /**
     * Attaches the fieldUIOverview behavior.
     *
     * @param {HTMLTableElement} table
     *   The table element for the overview.
     * @param {object} rowsData
     *   The data of the rows in the table.
     * @param {object} rowHandlers
     *   Handlers to be added to the rows.
     */
    attach(table, rowsData, rowHandlers) {
      const tableDrag = Drupal.tableDrag[table.id];

      // Add custom tabledrag callbacks.
      tableDrag.onDrop = this.onDrop;
      tableDrag.row.prototype.onSwap = this.onSwap;

      // Create row handlers.
      table.querySelectorAll('tr.draggable').forEach((row) => {
        // Extract server-side data for the row.
        if (row.id in rowsData) {
          const data = rowsData[row.id];
          data.tableDrag = tableDrag;

          // Create the row handler, make it accessible from the DOM row
          // element.
          const rowHandler = new rowHandlers[data.rowHandler](row, data);
          rowHandlersByElement.set(row, rowHandler);
        }
      });
    },

    /**
     * Event handler to be attached to form inputs triggering a region change.
     */
    onChange(event) {
      const trigger = event.currentTarget;
      const row = trigger.closest('tr');

      // Do not fire change listeners for items within forms that have their
      // own AJAX callbacks to process a change.
      if (trigger.closest('.ajax-new-content')) {
        return;
      }

      const rowHandler = rowHandlersByElement.get(row);
      const refreshRows = {};
      refreshRows[rowHandler.name] = trigger;

      // Handle region change.
      const region = rowHandler.getRegion();
      if (region !== rowHandler.region) {
        const fieldParent = row.querySelector('select.js-field-parent');
        if (fieldParent) {
          // Remove parenting.
          fieldParent.value = '';
        }
        // Let the row handler deal with the region change.
        Object.assign(refreshRows, rowHandler.regionChange(region));
        // Update the row region.
        rowHandler.region = region;
      }

      // Fields inside `.tabledrag-hide` are typically hidden. They can be
      // visible when "Show row weights" are enabled. If their value is changed
      // while visible, the row should be marked as changed, but they should not
      // be processed via AJAXRefreshRows as they are intended to be fields AJAX
      // updates the value of.
      if (trigger.closest('.tabledrag-hide')) {
        const thisTableDrag = rowHandler.tableDrag;
        // eslint-disable-next-line new-cap
        const rowObject = new thisTableDrag.row(
          row,
          '',
          thisTableDrag.indentEnabled,
          thisTableDrag.maxDepth,
          true,
        );
        rowObject.markChanged();
        rowObject.addChangedWarning();
      } else {
        // Ajax-update the rows.
        Drupal.fieldUIOverview.AJAXRefreshRows(
          refreshRows,
          row.closest('form'),
        );
      }
    },

    /**
     * Lets row handlers react when a row is dropped into a new region.
     */
    onDrop() {
      const dragObject = this;
      const row = dragObject.rowObject.element;
      const rowHandler = rowHandlersByElement.get(row);
      if (typeof rowHandler !== 'undefined') {
        let regionRow = row.previousElementSibling;
        while (regionRow && !regionRow.matches('tr.region-message')) {
          regionRow = regionRow.previousElementSibling;
        }
        if (!regionRow) {
          return;
        }
        const region = regionRow.className.replace(
          /([^ ]+[ ]+)*region-([^ ]+)-message([ ]+[^ ]+)*/,
          '$2',
        );

        if (region !== rowHandler.region) {
          // Let the row handler deal with the region change.
          const refreshRows = rowHandler.regionChange(region);
          // Update the row region.
          rowHandler.region = region;
          // Ajax-update the rows.
          Drupal.fieldUIOverview.AJAXRefreshRows(
            refreshRows,
            row.closest('form'),
          );
        }
      }
    },

    /**
     * Refreshes placeholder rows in empty regions while a row is being dragged.
     *
     * Copied from block.js.
     *
     * @param {HTMLElement} draggedRow
     *   The tableDrag rowObject for the row being dragged.
     */
    onSwap(draggedRow) {
      const rowObject = this;
      rowObject.table.querySelectorAll('tr.region-message').forEach((row) => {
        // If the dragged row is in this region, but above the message row, swap
        // it down one space.
        if (
          row.previousElementSibling ===
          rowObject.group[rowObject.group.length - 1]
        ) {
          // Prevent a recursion problem when using the keyboard to move rows
          // up.
          if (
            rowObject.method !== 'keyboard' ||
            rowObject.direction === 'down'
          ) {
            rowObject.swap('after', row);
          }
        }
        // This region has become empty.
        if (!row.nextElementSibling?.matches('.draggable')) {
          row.classList.remove('region-populated');
          row.classList.add('region-empty');
        }
        // This region has become populated.
        else if (row.matches('.region-empty')) {
          row.classList.replace('region-empty', 'region-populated');
        }
      });
    },

    /**
     * Triggers Ajax refresh of selected rows.
     *
     * The 'format type' selects can trigger a series of changes in child rows.
     * The #ajax behavior is therefore not attached directly to the selects, but
     * triggered manually through a hidden #ajax 'Refresh' button.
     *
     * @param {object} rows
     *   A hash object, whose keys are the names of the rows to refresh (they
     *   will receive the 'ajax-new-content' effect on the server side), and
     *   whose values are the DOM element in the row that should get an Ajax
     *   throbber.
     * @param {HTMLFormElement} form
     *   The form containing these rows.
     */
    AJAXRefreshRows(rows, form) {
      // Separate keys and values.
      const rowNames = [];
      const ajaxElements = [];
      Object.keys(rows || {}).forEach((rowName) => {
        rowNames.push(rowName);
        ajaxElements.push(rows[rowName]);
      });
      if (rowNames.length) {
        // Add a throbber next each of the ajaxElements.
        ajaxElements.forEach((element) => {
          element?.insertAdjacentHTML(
            'afterend',
            Drupal.theme.ajaxProgressThrobber(),
          );
        });
        const refreshRows = form.querySelector('input[name=refresh_rows]');
        if (refreshRows) {
          refreshRows.value = rowNames.join(' ');
        }
        const refresh = 'input[data-drupal-selector="edit-refresh"]';
        once('edit-refresh', refresh, form).forEach((input) => {
          // Keep track of the element that was focused prior to triggering the
          // mousedown event on the hidden submit button.
          let returnFocus = {
            drupalSelector: null,
            scrollY: null,
          };
          input.addEventListener('mousedown', () => {
            returnFocus = {
              drupalSelector: document.activeElement.hasAttribute(
                'data-drupal-selector',
              )
                ? document.activeElement.getAttribute('data-drupal-selector')
                : false,
              scrollY: window.scrollY,
            };
          });
          input.addEventListener('focus', () => {
            if (returnFocus.drupalSelector) {
              // Refocus the element that lost focus due to this hidden submit
              // button being triggered by a mousedown event.
              form
                .querySelector(
                  `[data-drupal-selector="${CSS.escape(returnFocus.drupalSelector)}"]`,
                )
                ?.focus();
            }
            // Ensure the scroll position is the same as when the input was
            // initially changed.
            window.scrollTo({
              top: returnFocus.scrollY,
            });
            returnFocus = {};
          });
        });
        form
          .querySelector(refresh)
          ?.dispatchEvent(
            new MouseEvent('mousedown', { bubbles: true, cancelable: true }),
          );

        // Disabled elements do not appear in POST ajax data, so we mark the
        // elements disabled only after firing the request.
        ajaxElements.forEach((element) => {
          if (element) {
            element.disabled = true;
          }
        });
      }
    },
  };

  /**
   * Row handlers for the 'Manage display' screen.
   *
   * @namespace
   */
  Drupal.fieldUIDisplayOverview = {};

  /**
   * Constructor for a 'field' row handler.
   *
   * This handler is used for both fields and 'extra fields' rows.
   *
   * @constructor
   *
   * @param {HTMLTableRowElement} row
   *   The row DOM element.
   * @param {object} data
   *   Additional data to be populated in the constructed object.
   *
   * @return {Drupal.fieldUIDisplayOverview.field}
   *   The field row handler constructed.
   */
  Drupal.fieldUIDisplayOverview.field = function (row, data) {
    this.row = row;
    this.name = data.name;
    this.region = data.region;
    this.tableDrag = data.tableDrag;
    this.defaultPlugin = data.defaultPlugin;
    this.pluginSelect = row.querySelector('.field-plugin-type');
    this.regionSelect = row.querySelector('select.field-region');

    // Attach change listeners to select and input elements in the row.
    row
      .querySelectorAll('select, input')
      .forEach((input) =>
        input.addEventListener('change', Drupal.fieldUIOverview.onChange),
      );

    return this;
  };

  Drupal.fieldUIDisplayOverview.field.prototype = {
    /**
     * Returns the region corresponding to the current form values of the row.
     *
     * @return {string}
     *   Either 'hidden' or 'content'.
     */
    getRegion() {
      return this.regionSelect?.value;
    },

    /**
     * Reacts to a row being changed regions.
     *
     * This function is called when the row is moved to a different region, as
     * a
     * result of either :
     * - a drag-and-drop action (the row's form elements then probably need to
     * be updated accordingly)
     * - user input in one of the form elements watched by the
     *   {@link Drupal.fieldUIOverview.onChange} change listener.
     *
     * @param {string} region
     *   The name of the new region for the row.
     *
     * @return {object}
     *   A hash object indicating which rows should be Ajax-updated as a result
     *   of the change, in the format expected by
     *   {@link Drupal.fieldUIOverview.AJAXRefreshRows}.
     */
    regionChange(region) {
      // Replace dashes with underscores.
      region = region.replace(/-/g, '_');

      if (this.regionSelect) {
        // Set the region of the select list.
        this.regionSelect.value = region;
      }

      // Restore the formatter back to the default formatter only if it was
      // disabled previously. Pseudo-fields do not have default formatters,
      // we just return to 'visible' for those.
      if (this.region === 'hidden' && this.pluginSelect) {
        const value = this.defaultPlugin ?? this.pluginSelect.options[0]?.value;
        if (value !== undefined) {
          this.pluginSelect.value = value;
        }
      }

      return { [this.name]: this.pluginSelect };
    },
  };

  /**
   * Filters the existing fields table by the field name or field type.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.tableFilterByText = {
    attach(context) {
      once('table-filter-text', '.js-table-filter-text', context).forEach(
        (input) => {
          const table = (input.form || context).querySelector(
            input.dataset.table,
          );
          if (!table) {
            return;
          }
          const rows = table.querySelectorAll('tbody tr');
          let searching = false;

          function filterRows(event) {
            const query = event.target.value.toLowerCase();
            if (query.length > 0) {
              searching = true;
              rows.forEach((row) => {
                const text = Array.from(
                  row.querySelectorAll('.form-item'),
                  (item) => item.textContent,
                ).join(' ');
                row.style.display = text.toLowerCase().includes(query)
                  ? ''
                  : 'none';
              });
            } else if (searching) {
              searching = false;
              rows.forEach((row) => {
                row.style.display = '';
              });
            }
          }

          function preventEnterKey(event) {
            if (event.key === 'Enter') {
              event.preventDefault();
              event.stopPropagation();
            }
          }

          input.addEventListener('keyup', debounce(filterRows, 200));
          input.addEventListener('keydown', preventEnterKey);
        },
      );
    },
  };

  /**
   * Allows users to select an element which checks a radio button and
   * adds a class used for css styling on different states.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches behavior for selecting field.
   */
  Drupal.behaviors.clickToSelect = {
    attach(context) {
      once('field-click-to-select', '.js-click-to-select', context).forEach(
        (clickToSelectEl) => {
          const input = clickToSelectEl.querySelector('input');
          if (!input) {
            return;
          }
          this.clickHandler(clickToSelectEl, input);
          input.addEventListener('focus', () =>
            clickToSelectEl.classList.add('focus'),
          );
          input.addEventListener('blur', () =>
            clickToSelectEl.classList.remove('focus'),
          );
          if (input.classList.contains('error')) {
            clickToSelectEl.classList.add('error');
          }
          if (input.checked) {
            this.selectHandler(clickToSelectEl, input);
          }
        },
      );
    },
    // Adds click event listener to the field card.
    clickHandler(clickToSelectEl, input) {
      clickToSelectEl.addEventListener('click', (event) => {
        const clickToSelect = event.target.closest('.js-click-to-select');
        this.selectHandler(clickToSelect, input);
        input.dispatchEvent(
          new Event('updateOptions', { bubbles: true, cancelable: true }),
        );
      });
    },
    // Handles adding and removing classes for the different states.
    selectHandler(clickToSelect, input) {
      input.checked = true;
      clickToSelect
        .closest('form')
        ?.querySelectorAll('.js-click-to-select.selected')
        .forEach((item) => {
          item.classList.remove('selected');
        });
      clickToSelect.classList.add('selected');
      // Ensure focus is added at the end of the process so wrap in
      // a timeout.
      setTimeout(() => {
        // Remove the disabled attribute added by Drupal ajax so the
        // element is focusable. This is safe as clicking the element
        // multiple times causes no problems.
        input.removeAttribute('disabled');
        input.focus();
      }, 0);
    },
  };
})(Drupal, Drupal.debounce, once);
