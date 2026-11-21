/**
 * @file
 * Responsive table functionality.
 */

(function (Drupal, once, window) {
  /**
   * The TableResponsive object optimizes table presentation for screen size.
   *
   * A responsive table hides columns at small screen sizes, leaving the most
   * important columns visible to the end user. Users should not be prevented
   * from accessing all columns, however. This class adds a toggle to a table
   * with hidden columns that exposes the columns. Exposing the columns will
   * likely break layouts, but it provides the user with a means to access
   * data, which is a guiding principle of responsive design.
   *
   * @constructor Drupal.TableResponsive
   *
   * @param {HTMLElement} table
   *   The table element to initialize the responsive table on.
   */
  function TableResponsive(table) {
    this.table = table;
    this.showText = Drupal.t('Show all columns');
    this.hideText = Drupal.t('Hide lower priority columns');
    this.headers = [...table.querySelectorAll('th')];
    this.revealedCells = [];

    this.link = document.createElement('button');
    this.link.type = 'button';
    this.link.className = 'link tableresponsive-toggle';
    this.link.title = Drupal.t(
      'Show table cells that were hidden to make the table fit within a small screen.',
    );
    this.link.addEventListener(
      'click',
      this.eventhandlerToggleColumns.bind(this),
    );

    const wrapper = document.createElement('div');
    wrapper.className = 'tableresponsive-toggle-columns';
    wrapper.append(this.link);
    table.before(wrapper);

    // Capture the current handler, as a later assignment should only affect
    // newly created tables.
    const evaluate = this.eventhandlerEvaluateColumnVisibility.bind(this);
    window.addEventListener('resize', evaluate);
    TableResponsive.resizeHandlers.set(this, evaluate);
  }

  /**
   * Finds priority headers hidden by the current layout.
   *
   * @param {HTMLElement[]} headers
   *   The table headers.
   * @return {HTMLElement[]}
   *   The hidden priority headers in table order.
   */
  function hiddenPriorityHeaders(headers) {
    return headers.filter(
      (header) =>
        header.matches('.priority-medium, .priority-low') &&
        Drupal.elementIsHidden(header),
    );
  }

  /**
   * Attach the tableResponsive function to {@link Drupal.behaviors}.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches tableResponsive functionality.
   */
  Drupal.behaviors.tableResponsive = {
    attach(context) {
      once('tableresponsive', 'table.responsive-enabled', context).forEach(
        (table) => {
          TableResponsive.tables.push(new TableResponsive(table));
        },
      );
      if (TableResponsive.tables.length) {
        refreshToggles();
      }
    },
  };

  /**
   * Refreshes the visibility of all responsive toggles.
   */
  function refreshToggles() {
    const event = new Event('resize');
    TableResponsive.tables.forEach((table) =>
      TableResponsive.resizeHandlers.get(table)(event),
    );
    window.dispatchEvent(new Event('drupal:tableresponsive-refreshed'));
  }

  /**
   * Stores all created instances.
   *
   * @type {Drupal.TableResponsive[]}
   */
  TableResponsive.tables = [];
  TableResponsive.resizeHandlers = new WeakMap();

  Object.assign(TableResponsive.prototype, {
    /**
     * Updates the toggle after a change in viewport size.
     *
     * @param {Event} event
     *   The resize event.
     */
    eventhandlerEvaluateColumnVisibility(event) {
      const pegged = this.link.dataset.pegged === '1';
      const hiddenLength = hiddenPriorityHeaders(this.headers).length;
      if (hiddenLength > 0) {
        this.link.style.display = '';
        this.link.textContent = this.showText;
      }
      // Keep the toggle visible after a user interaction, even if the user
      // resizes the window and column visibility changes.
      if (!pegged && hiddenLength === 0) {
        this.link.style.display = 'none';
        this.link.textContent = this.hideText;
      }
    },

    /**
     * Toggles the visibility of lower-priority columns.
     *
     * @param {MouseEvent} event
     *   The click event.
     */
    eventhandlerToggleColumns(event) {
      event.preventDefault();
      const hiddenHeaders = hiddenPriorityHeaders(this.headers);
      if (hiddenHeaders.length) {
        const rows = this.table.querySelectorAll('tbody tr');
        const revealed = new Set(this.revealedCells);
        hiddenHeaders.forEach((header) => {
          let position = 0;
          for (
            let sibling = header.previousElementSibling;
            sibling;
            sibling = sibling.previousElementSibling
          ) {
            if (sibling.matches('th')) {
              position += 1;
            }
          }
          rows.forEach((row) => {
            const cell = row.querySelectorAll('td')[position];
            if (cell) {
              revealCell(cell);
              revealed.add(cell);
            }
          });
          revealCell(header);
          revealed.add(header);
        });
        this.revealedCells = [...revealed];
        this.link.textContent = this.hideText;
        this.link.dataset.pegged = '1';
      } else {
        // Restore the display rules from the stylesheet.
        this.revealedCells.forEach((cell) =>
          cell.style.removeProperty('display'),
        );
        this.link.textContent = this.showText;
        this.link.dataset.pegged = '0';
        refreshToggles();
      }
    },
  });

  /**
   * Reveals a table cell hidden by an inline style or responsive CSS.
   *
   * @param {HTMLElement} cell
   *   The table cell.
   */
  function revealCell(cell) {
    if (cell.style.display === 'none') {
      cell.style.removeProperty('display');
    }
    if (window.getComputedStyle(cell).display === 'none') {
      cell.style.display = 'table-cell';
    }
  }

  // Make the TableResponsive object available in the Drupal namespace.
  TableResponsive.eventCallbacks = Object.freeze({
    eventhandlerEvaluateColumnVisibility:
      TableResponsive.prototype.eventhandlerEvaluateColumnVisibility,
    eventhandlerToggleColumns:
      TableResponsive.prototype.eventhandlerToggleColumns,
  });
  Drupal.TableResponsive = TableResponsive;
  document.dispatchEvent(new Event('drupal:tableresponsive-ready'));
})(Drupal, once, window);
