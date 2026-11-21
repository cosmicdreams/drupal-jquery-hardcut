/**
 * @file
 * Provide dragging capabilities to admin uis.
 */

/**
 * Triggers when weights columns are toggled.
 *
 * @event columnschange
 */

(function (Drupal, drupalSettings, once) {
  const draggableRows = ':scope > tr.draggable, :scope > tbody > tr.draggable';

  const { tableInteraction } = Drupal;
  const weightColspans = new WeakMap();
  const retainedWeightToggles = new WeakMap();
  const retainedRowHandles = new WeakMap();

  /**
   * Updates the span of a cell when its weight column changes visibility.
   *
   * @param {HTMLTableCellElement} cell
   *   The cell that spans the weight column.
   * @param {boolean} show
   *   Whether the weight column is visible.
   */
  function setWeightColspan(cell, show) {
    const state = weightColspans.get(cell);
    if (state?.shown !== show) {
      const span = state?.span ?? cell.colSpan;
      cell.colSpan += show ? 1 : -1;
      weightColspans.set(cell, { span, shown: show });
    }
  }

  /**
   * Finds an adjacent element matching a row selector.
   *
   * @param {Element} row
   *   The current row.
   * @param {string} direction
   *   The sibling property to read.
   * @param {string} [selector]
   *   The selector the adjacent row must match.
   * @return {Element|undefined}
   *   The matching sibling.
   */
  function adjacentRow(row, direction, selector = 'tr') {
    const sibling = row[direction];
    return sibling?.matches(selector) ? sibling : undefined;
  }

  /**
   * Finds descendants in order, without duplicates from nested rows.
   *
   * @param {Element[]} elements
   *   The elements to search.
   * @param {string} selector
   *   The descendant selector.
   * @return {Element[]}
   *   The matching elements.
   */
  function descendants(elements, selector) {
    return [
      ...new Set(
        elements.flatMap((element) => [...element.querySelectorAll(selector)]),
      ),
    ];
  }

  /**
   * Finds the document-relative position of an element.
   *
   * @param {Element} element
   *   The element to measure.
   * @return {{top: number, left: number}}
   *   The element offset.
   */
  function offset(element) {
    if (!element.getClientRects().length) {
      return { top: 0, left: 0 };
    }
    const { top, left } = element.getBoundingClientRect();
    return { top: top + window.scrollY, left: left + window.scrollX };
  }
  /**
   * Store the state of weight columns display for all tables.
   *
   * Default value is to hide weight columns.
   */
  let showWeight = JSON.parse(
    localStorage.getItem('Drupal.tableDrag.showWeight'),
  );

  /**
   * Drag and drop table rows with field manipulation.
   *
   * Using the drupal_attach_tabledrag() function, any table with weights or
   * parent relationships may be made into draggable tables. Columns containing
   * a field may optionally be hidden, providing a better user experience.
   *
   * Created tableDrag instances may be modified with custom behaviors by
   * overriding the .onDrag, .onDrop, .row.onSwap, and .row.onIndent methods.
   * See blocks.js for an example of adding additional functionality to
   * tableDrag.
   *
   * With core/jquery loaded, assigned overrides receive legacy events.
   * To keep native events on mixed pages, set nativeTableEvent to true on
   * the override or on the saved wrapper returned by the method getter.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.tableDrag = {
    attach(context, settings) {
      Object.keys(settings.tableDrag || {}).forEach((base) => {
        const [table] = once(
          'tabledrag',
          `[data-drupal-tabledrag="${CSS.escape(base)}"]`,
          context,
        );
        if (table) {
          Drupal.tableDrag[base] = new Drupal.tableDrag(
            table,
            settings.tableDrag[base],
          );
        }
      });
    },
  };

  /**
   * Provides table and field manipulation.
   *
   * @constructor
   *
   * @param {HTMLElement} table
   *   DOM object for the table to be made draggable.
   * @param {object} tableSettings
   *   Settings for the table added via drupal_add_dragtable().
   */
  Drupal.tableDrag = function (table, tableSettings) {
    const self = this;
    adoptEventOverrides(this);
    /**
     *
     * @type {HTMLElement}
     */
    this.table = table;

    /**
     * @type {object}
     */
    this.tableSettings = tableSettings;

    /**
     * Used to hold information about a current drag operation.
     *
     * @type {?HTMLElement}
     */
    this.dragObject = null;

    /**
     * Provides operations for row manipulation.
     *
     * @type {?HTMLElement}
     */
    this.rowObject = null;

    /**
     * Remember the previous element.
     *
     * @type {?HTMLElement}
     */
    this.oldRowElement = null;

    /**
     * Used to determine up or down direction from last mouse move.
     *
     * @type {?number}
     */
    this.oldY = null;

    /**
     * Whether anything in the entire table has changed.
     *
     * @type {boolean}
     */
    this.changed = false;

    /**
     * Maximum amount of allowed parenting.
     *
     * @type {number}
     */
    this.maxDepth = 0;

    /**
     * Direction of the table.
     *
     * @type {number}
     */
    this.rtl = window.getComputedStyle(this.table).direction === 'rtl' ? -1 : 1;

    /**
     *
     * @type {boolean}
     */
    this.striping = table.dataset.striping === '1';

    /**
     * Configure the scroll settings.
     *
     * @type {object}
     *
     * @prop {number} amount
     * @prop {number} interval
     * @prop {number} trigger
     */
    this.scrollSettings = { amount: 4, interval: 50, trigger: 70 };

    /**
     *
     * @type {?number}
     */
    this.scrollInterval = null;

    /**
     *
     * @type {number}
     */
    this.scrollY = 0;

    /**
     *
     * @type {number}
     */
    this.windowHeight = 0;

    /**
     * @type {?HTMLButtonElement}
     */
    this.toggleWeightButton = null;

    /**
     * Check this table's settings for parent relationships.
     *
     * For efficiency, large sections of code can be skipped if we don't need to
     * track horizontal movement and indentations.
     *
     * @type {boolean}
     */
    this.indentEnabled = false;

    /**
     * Keeps track of rows that have changed.
     */
    this.changedRowIds = Drupal.tableDrag[table.id]
      ? Drupal.tableDrag[table.id].changedRowIds
      : new Set();

    Object.keys(tableSettings || {}).forEach((group) => {
      Object.keys(tableSettings[group] || {}).forEach((n) => {
        if (tableSettings[group][n].relationship === 'parent') {
          this.indentEnabled = true;
        }
        if (tableSettings[group][n].limit > 0) {
          this.maxDepth = tableSettings[group][n].limit;
        }
      });
    });
    this.dragOrientation = this.indentEnabled ? 'drag' : 'drag-y';
    if (this.indentEnabled) {
      // If a tabledrag has indents but only has leaf rows are present, it's not
      // hierarchical from an end-user point of view.
      const countNestableRows = [
        ...table.querySelectorAll(draggableRows),
      ].filter((row) => !row.matches('.tabledrag-leaf')).length;
      // There are no rows that accept nesting in this table, show a vertical
      // drag icon.
      if (countNestableRows === 0) {
        this.dragOrientation = 'drag-y';
      }
      /**
       * Total width of indents, set in makeDraggable.
       *
       * @type {number}
       */
      this.indentCount = 1;
      // Find the width of indentations to measure mouse movements against.
      // Because the table doesn't need to start with any indentations, we
      // manually append 2 indentations in the first draggable row, measure
      // the offset, then remove.
      const testRow = document.createElement('tr');
      testRow.classList.add('draggable');
      table.append(testRow);
      const testCell = testRow.insertCell();
      testCell.prepend(
        tableDrag.themeContent(Drupal.theme('tableDragIndentation')),
        tableDrag.themeContent(Drupal.theme('tableDragIndentation')),
      );
      const [firstIndent, secondIndent] =
        testCell.querySelectorAll('.js-indentation');

      /**
       *
       * @type {number}
       */
      this.indentAmount = secondIndent.offsetLeft - firstIndent.offsetLeft;
      testRow.remove();
    }
    // Make each applicable row draggable.
    // Match immediate children of the parent element to allow nesting.
    table
      .querySelectorAll(draggableRows)
      .forEach((row) => self.makeDraggable(row));

    let toggle = retainedWeightToggles.get(table);
    retainedWeightToggles.delete(table);
    if (
      !toggle ||
      !toggle.nodes.every((node) => node.parentNode === table.parentNode)
    ) {
      const markup = tableDrag.themeContent(Drupal.theme('tableDragToggle'));
      if (markup instanceof Node) {
        toggle = {
          button:
            markup.querySelector?.(
              '[data-drupal-selector="tabledrag-toggle-weight"]',
            ) ?? null,
          nodes:
            markup instanceof DocumentFragment
              ? [...markup.childNodes]
              : [markup],
        };
        table.before(markup);
      } else {
        toggle = undefined;
      }
    }
    this.toggleWeightButton = toggle?.button ?? null;
    if (this.toggleWeightButton) {
      tableInteraction.listen(
        table,
        this.toggleWeightButton,
        'click',
        (event) => {
          event.preventDefault();
          this.toggleColumns();
        },
      );
    }

    // Initialize the specified columns (for example, weight or parent columns)
    // to show or hide according to user preference. This aids accessibility
    // so that, e.g., screen reader users can choose to enter weight values and
    // manipulate form elements directly, rather than using drag-and-drop.
    self.initColumns();

    // Add event bindings to the document. The self variable is passed along
    // as event handlers do not have direct access to the tableDrag object.
    /**
     * Routes a document movement to this table's active drag.
     *
     * @param {MouseEvent|PointerEvent|TouchEvent} event
     *   The document movement.
     */
    function move(event) {
      const pointer = event.type === 'touchmove' ? event.touches?.[0] : event;
      const result = self.dragRow(pointer, self);
      if (result === false) {
        event.preventDefault();
        event.stopPropagation();
      }
      return result;
    }
    tableInteraction.listen(table, document, 'touchmove', move);
    ['mousemove', 'pointermove'].forEach((type) => {
      tableInteraction.listen(table, document, type, move);
    });
    tableInteraction.listen(table, document, 'touchend', (event) =>
      self.dropRow(event.changedTouches?.[0] ?? event.touches?.[0], self),
    );
    ['mouseup', 'pointerup'].forEach((type) => {
      tableInteraction.listen(table, document, type, (event) =>
        self.dropRow(event, self),
      );
    });

    // React to localStorage event showing or hiding weight columns.
    const updateStoredWeights = (event) => {
      if (event.key === 'Drupal.tableDrag.showWeight') {
        showWeight = JSON.parse(event.newValue);
        this.displayColumns(showWeight);
      }
    };
    window.addEventListener('storage', updateStoredWeights);
    tableInteraction.own(table, window, () => {
      window.removeEventListener('storage', updateStoredWeights);
      clearInterval(self.scrollInterval);
      if (self.dragObject) {
        document.body.classList.remove(self.dragOrientation);
      }
      self.dragObject = null;
      self.rowObject = null;
      if (toggle?.nodes.length) {
        retainedWeightToggles.set(table, toggle);
        tableInteraction.removeOrphanedControls(table, toggle.nodes);
      }
      once.remove('tabledrag', table);
    });

    // Check for any rows marked as changed before this tabledrag was rerendered
    // and mark them as changed for this current render.
    this.changedRowIds.forEach((changedRowId) => {
      const row = document.getElementById(changedRowId);
      if (!row) {
        return;
      }
      const rowObject = new self.row(
        row,
        '',
        self.indentEnabled,
        self.maxDepth,
        true,
      );
      rowObject.markChanged();
    });
  };

  /**
   * Initialize columns containing form elements to be hidden by default.
   *
   * Identify and mark each cell with a CSS class so we can easily toggle
   * show/hide it. Finally, hide columns if user does not have a
   * 'Drupal.tableDrag.showWeight' localStorage value.
   */
  Drupal.tableDrag.prototype.initColumns = function () {
    Object.values(this.tableSettings || {}).forEach((settings) => {
      Object.values(settings).some(({ target, hidden }) => {
        const cell = this.table
          .querySelector(`.${CSS.escape(target)}`)
          ?.closest('td');
        if (!hidden || !cell) {
          return false;
        }

        // Count data cells, as the settings identify a field in the body.
        const columnIndex =
          [...cell.parentElement.querySelectorAll(':scope > td')].indexOf(
            cell,
          ) + 1;
        const markCell = this.addColspanClass(columnIndex);
        this.table
          .querySelectorAll(
            ':scope > thead > tr, :scope > tbody > tr, :scope > tr',
          )
          .forEach((row, index) => markCell.call(row, index, row));
        return true;
      });
    });
    this.displayColumns(showWeight);
  };

  /**
   * Mark cells that have colspan.
   *
   * In order to adjust the colspan instead of hiding them altogether.
   *
   * @param {number} columnIndex
   *   The column index to add colspan class to.
   *
   * @return {function}
   *   Function to add colspan class.
   */
  Drupal.tableDrag.prototype.addColspanClass = function (columnIndex) {
    return function () {
      // Get the columnIndex and adjust for any colspans in this row.
      let index = columnIndex;
      const cells = [...this.children];
      const columnSpan = (cell) =>
        weightColspans.get(cell)?.span ?? cell.colSpan;
      cells.forEach((cell, position) => {
        const span = columnSpan(cell);
        if (position < index && span > 1) {
          index -= span - 1;
        }
      });
      const cell = cells[index - 1];
      if (cell) {
        cell.classList.add(
          columnSpan(cell) > 1 ? 'tabledrag-has-colspan' : 'tabledrag-hide',
        );
      }
    };
  };

  /**
   * Hide or display weight columns. Triggers an event on change.
   *
   * @fires event:columnschange
   *
   * @param {boolean} displayWeight
   *   'true' will show weight columns.
   */
  Drupal.tableDrag.prototype.displayColumns = function (displayWeight) {
    if (displayWeight) {
      this.showColumns();
    }
    // Default action is to hide columns.
    else {
      this.hideColumns();
    }

    this.toggleWeightButton?.replaceChildren(
      tableDrag.themeContent(
        Drupal.theme('toggleButtonContent', displayWeight),
      ),
    );

    // Publish an event to allow other scripts to react to this display change.
    once.filter('tabledrag', 'table').forEach((table) => {
      Drupal.eventBindings.publish(
        table,
        new CustomEvent('columnschange', {
          bubbles: true,
          cancelable: true,
          detail: { displayWeight: !!displayWeight },
        }),
      );
    });
  };

  /**
   * Toggle the weight column depending on 'showWeight' value.
   *
   * Store only default override.
   */
  Drupal.tableDrag.prototype.toggleColumns = function () {
    showWeight = !showWeight;
    this.displayColumns(showWeight);
    if (showWeight) {
      // Save default override.
      localStorage.setItem('Drupal.tableDrag.showWeight', showWeight);
    } else {
      // Reset the value to its default.
      localStorage.removeItem('Drupal.tableDrag.showWeight');
    }
  };

  /**
   * Hide the columns containing weight/parent form elements.
   *
   * Undo showColumns().
   */
  Drupal.tableDrag.prototype.hideColumns = function () {
    const tables = once.filter('tabledrag', 'table');
    // Hide weight/parent cells and headers.
    descendants(tables, '.tabledrag-hide').forEach((element) => {
      element.style.display = 'none';
    });
    // Show TableDrag handles.
    descendants(tables, '.tabledrag-handle').forEach((element) => {
      element.style.display = '';
    });
    // Reduce the colspan of any effected multi-span columns.
    descendants(tables, '.tabledrag-has-colspan').forEach((element) =>
      setWeightColspan(element, false),
    );
  };

  /**
   * Show the columns containing weight/parent form elements.
   *
   * Undo hideColumns().
   */
  Drupal.tableDrag.prototype.showColumns = function () {
    const tables = once.filter('tabledrag', 'table');
    // Show weight/parent cells and headers.
    descendants(tables, '.tabledrag-hide').forEach((element) => {
      element.style.display = '';
    });
    // Hide TableDrag handles.
    descendants(tables, '.tabledrag-handle').forEach((element) => {
      element.style.display = 'none';
    });
    // Increase the colspan for any columns where it was previously reduced.
    descendants(tables, '.tabledrag-has-colspan').forEach((element) =>
      setWeightColspan(element, true),
    );
  };

  /**
   * Find the target used within a particular row and group.
   *
   * @param {string} group
   *   Group selector.
   * @param {HTMLElement} row
   *   The row HTML element.
   *
   * @return {object}
   *   The table row settings.
   */
  Drupal.tableDrag.prototype.rowSettings = function (group, row) {
    const field = row.querySelector(`.${CSS.escape(group)}`);
    const tableSettingsGroup = this.tableSettings[group];
    return Object.keys(tableSettingsGroup)
      .map((delta) => {
        const targetClass = tableSettingsGroup[delta].target;
        let rowSettings;
        if (field?.classList.contains(targetClass)) {
          // Return a copy of the row settings.
          rowSettings = {};
          Object.keys(tableSettingsGroup[delta]).forEach((n) => {
            rowSettings[n] = tableSettingsGroup[delta][n];
          });
        }
        return rowSettings;
      })
      .filter((rowSetting) => rowSetting)[0];
  };

  /**
   * Take an item and add event handlers to make it become draggable.
   *
   * @param {HTMLElement} item
   *   The item to add event handlers to.
   */
  Drupal.tableDrag.prototype.makeDraggable = function (item) {
    const self = this;
    // Add a class to the title link.
    const firstCells = [...item.querySelectorAll('td:first-of-type')];
    descendants(firstCells, 'a').forEach((link) =>
      link.classList.add('menu-item__link'),
    );
    const lastIndentation = descendants(firstCells, '.js-indentation').at(-1);
    let handle = retainedRowHandles.get(item);
    retainedRowHandles.delete(item);
    if (!handle || !item.contains(handle)) {
      const handleContent = tableDrag.themeContent(
        Drupal.theme('tableDragHandle', this.dragOrientation),
      );
      handle =
        handleContent instanceof Element
          ? handleContent
          : handleContent.firstElementChild;
      if (this.dragOrientation === 'drag-y') {
        handle.classList.add('tabledrag-handle-y');
      }
      if (lastIndentation) {
        lastIndentation.after(handleContent);
      } else {
        item.querySelector('td')?.prepend(handleContent);
      }
    }
    if (lastIndentation) {
      // Update the total width of indentation in this entire table.
      self.indentCount = Math.max(
        item.querySelectorAll('.js-indentation').length,
        self.indentCount,
      );
    }

    tableInteraction.own(self.table, handle, () =>
      retainedRowHandles.set(item, handle),
    );

    ['mousedown', 'touchstart', 'pointerdown'].forEach((type) => {
      tableInteraction.listen(self.table, handle, type, (event) => {
        event.preventDefault();
        const pointer =
          event.type === 'touchstart' ? event.touches?.[0] : event;
        self.dragStart(pointer, self, item);
      });
    });

    // Prevent the anchor tag from jumping us to the top of the page.
    tableInteraction.listen(self.table, handle, 'click', (e) => {
      e.preventDefault();
    });

    // Set blur cleanup when a handle is focused.
    tableInteraction.listen(self.table, handle, 'focus', () => {
      self.safeBlur = true;
    });

    // On blur, fire the same function as a touchend/mouseup. This is used to
    // update values after a row has been moved through the keyboard support.
    tableInteraction.listen(self.table, handle, 'blur', (event) => {
      if (self.rowObject && self.safeBlur) {
        self.dropRow(event, self);
      }
    });

    // Add arrow-key support to the handle.
    tableInteraction.listen(self.table, handle, 'keydown', (event) => {
      // If a rowObject doesn't yet exist and this isn't the tab key.
      if (event.keyCode !== 9 && !self.rowObject) {
        self.rowObject = new self.row(
          item,
          'keyboard',
          self.indentEnabled,
          self.maxDepth,
          true,
        );
      }

      let keyChange = false;
      let groupHeight;

      /* eslint-disable no-fallthrough */

      switch (event.keyCode) {
        // Left arrow.
        case 37:
        // Safari left arrow.
        case 63234:
          keyChange = true;
          self.rowObject.indent(-1 * self.rtl);
          break;

        // Up arrow.
        case 38:
        // Safari up arrow.
        case 63232: {
          let previousRow = adjacentRow(
            self.rowObject.element,
            'previousElementSibling',
          );
          while (previousRow && Drupal.elementIsHidden(previousRow)) {
            previousRow = adjacentRow(previousRow, 'previousElementSibling');
          }
          if (previousRow) {
            // Do not allow the onBlur cleanup.
            self.safeBlur = false;
            self.rowObject.direction = 'up';
            keyChange = true;

            if (item.matches('.tabledrag-root')) {
              // Swap with the previous top-level row.
              groupHeight = 0;
              while (
                previousRow &&
                previousRow.querySelector('.js-indentation')
              ) {
                previousRow = adjacentRow(
                  previousRow,
                  'previousElementSibling',
                );
                if (previousRow && !Drupal.elementIsHidden(previousRow)) {
                  groupHeight += previousRow.offsetHeight;
                }
              }
              if (previousRow) {
                self.rowObject.swap('before', previousRow);
                // No need to check for indentation, 0 is the only valid one.
                window.scrollBy(0, -groupHeight);
              }
            } else if (
              self.table.tBodies[0].rows[0] !== previousRow ||
              previousRow.matches('.draggable')
            ) {
              // Swap with the previous row (unless previous row is the first
              // one and un-draggable).
              self.rowObject.swap('before', previousRow);
              self.rowObject.interval = null;
              self.rowObject.indent(0);
              window.scrollBy(0, -parseInt(item.offsetHeight, 10));
            }
            // Regain focus after the DOM manipulation.
            handle.focus();
            self.safeBlur = true;
          }
          break;
        }
        // Right arrow.
        case 39:
        // Safari right arrow.
        case 63235:
          keyChange = true;
          self.rowObject.indent(self.rtl);
          break;

        // Down arrow.
        case 40:
        // Safari down arrow.
        case 63233: {
          let nextRow = adjacentRow(
            self.rowObject.group.at(-1),
            'nextElementSibling',
          );
          while (nextRow && Drupal.elementIsHidden(nextRow)) {
            nextRow = adjacentRow(nextRow, 'nextElementSibling');
          }
          if (nextRow) {
            // Do not allow the onBlur cleanup.
            self.safeBlur = false;
            self.rowObject.direction = 'down';
            keyChange = true;

            if (item.matches('.tabledrag-root')) {
              // Swap with the next group (necessarily a top-level one).
              groupHeight = 0;
              const nextGroup = new self.row(
                nextRow,
                'keyboard',
                self.indentEnabled,
                self.maxDepth,
                false,
              );
              if (nextGroup) {
                nextGroup.group.forEach((row) => {
                  groupHeight += Drupal.elementIsHidden(row)
                    ? 0
                    : row.offsetHeight;
                });
                const nextGroupRow = nextGroup.group.at(-1);
                self.rowObject.swap('after', nextGroupRow);
                // No need to check for indentation, 0 is the only valid one.
                window.scrollBy(0, parseInt(groupHeight, 10));
              }
            } else {
              // Swap with the next row.
              self.rowObject.swap('after', nextRow);
              self.rowObject.interval = null;
              self.rowObject.indent(0);
              window.scrollBy(0, parseInt(item.offsetHeight, 10));
            }
            // Regain focus after the DOM manipulation.
            handle.focus();
            self.safeBlur = true;
          }
          break;
        }
      }

      /* eslint-enable no-fallthrough */

      if (self.rowObject && self.rowObject.changed === true) {
        item.classList.add('drag');
        if (self.oldRowElement) {
          self.oldRowElement.classList.remove('drag-previous');
        }
        self.oldRowElement = item;
        if (self.striping === true) {
          self.restripeTable();
        }
        self.onDrag();
      }

      // Returning false if we have an arrow key to prevent scrolling.
      if (keyChange) {
        event.preventDefault();
        event.stopPropagation();
        return false;
      }
      return undefined;
    });

    // Compatibility addition, return false on keypress to prevent unwanted
    // scrolling. IE and Safari will suppress scrolling on keydown, but all
    // other browsers need to return false on keypress.
    // http://www.quirksmode.org/js/keys.html
    tableInteraction.listen(self.table, handle, 'keypress', (event) => {
      /* eslint-disable no-fallthrough */

      switch (event.keyCode) {
        // Left arrow.
        case 37:
        // Up arrow.
        case 38:
        // Right arrow.
        case 39:
        // Down arrow.
        case 40:
          event.preventDefault();
          event.stopPropagation();
          return false;
      }

      /* eslint-enable no-fallthrough */
      return undefined;
    });
  };

  /**
   * Pointer event initiator, creates drag object and information.
   *
   * @param {MouseEvent|PointerEvent|Touch} event
   *   The event object that trigger the drag.
   * @param {Drupal.tableDrag} self
   *   The drag handle.
   * @param {HTMLElement} item
   *   The item that is being dragged.
   */
  Drupal.tableDrag.prototype.dragStart = function (event, self, item) {
    // Commit keyboard changes before beginning the pointer drag. A handle
    // that already lost focus needs an explicit blur to finish the drop.
    if (self.rowObject?.method === 'keyboard') {
      const row = self.rowObject;
      const handle = row.element.querySelector('a.tabledrag-handle');
      handle?.blur();
      if (self.rowObject === row) {
        handle?.dispatchEvent(new FocusEvent('blur'));
      }
    }

    // Create a new dragObject recording the pointer information.
    self.dragObject = {};
    self.dragObject.initOffset = self.getPointerOffset(item, event);
    self.dragObject.initPointerCoords = self.pointerCoords(event);
    if (self.indentEnabled) {
      self.dragObject.indentPointerPos = self.dragObject.initPointerCoords;
    }

    // Create a new rowObject for manipulation of this row.
    self.rowObject = new self.row(
      item,
      'pointer',
      self.indentEnabled,
      self.maxDepth,
      true,
    );

    // Save the position of the table.
    self.table.topY = offset(self.table).top;
    self.table.bottomY = self.table.topY + self.table.offsetHeight;

    // Add classes to the handle and row.
    item.classList.add('drag');

    // Set the document to use the move cursor during drag.
    document.body.classList.add(this.dragOrientation);
    if (self.oldRowElement) {
      self.oldRowElement.classList.remove('drag-previous');
    }

    // Set the initial y coordinate so the direction can be calculated in
    // dragRow().
    self.oldY = self.pointerCoords(event).y;
  };

  /**
   * Pointer movement handler, bound to document.
   *
   * @param {MouseEvent|PointerEvent|Touch} event
   *   The pointer event.
   * @param {Drupal.tableDrag} self
   *   The tableDrag instance.
   *
   * @return {boolean|undefined}
   *   Undefined if no dragObject is defined, false otherwise.
   */
  Drupal.tableDrag.prototype.dragRow = function (event, self) {
    if (self.dragObject) {
      self.currentPointerCoords = self.pointerCoords(event);
      const y = self.currentPointerCoords.y - self.dragObject.initOffset.y;
      const x = self.currentPointerCoords.x - self.dragObject.initOffset.x;

      // Check for row swapping and vertical scrolling.
      if (y !== self.oldY) {
        self.rowObject.direction = y > self.oldY ? 'down' : 'up';
        // Update the old value.
        self.oldY = y;
        // Check if the window should be scrolled (and how fast).
        const scrollAmount = self.checkScroll(self.currentPointerCoords.y);
        // Stop any current scrolling.
        clearInterval(self.scrollInterval);
        // Continue scrolling if the mouse has moved in the scroll direction.
        if (
          (scrollAmount > 0 && self.rowObject.direction === 'down') ||
          (scrollAmount < 0 && self.rowObject.direction === 'up')
        ) {
          self.setScroll(scrollAmount);
        }

        // If we have a valid target, perform the swap and restripe the table.
        const currentRow = self.findDropTargetRow(x, y);
        if (currentRow) {
          if (self.rowObject.direction === 'down') {
            self.rowObject.swap('after', currentRow, self);
          } else {
            self.rowObject.swap('before', currentRow, self);
          }
          if (self.striping === true) {
            self.restripeTable();
          }
        }
      }

      // Similar to row swapping, handle indentations.
      if (self.indentEnabled) {
        const xDiff =
          self.currentPointerCoords.x - self.dragObject.indentPointerPos.x;
        // Set the number of indentations the pointer has been moved left or
        // right.
        const indentDiff = Math.round(
          xDiff / (Math.abs(self.indentAmount) * self.rtl),
        );
        // Indent the row with our estimated diff, which may be further
        // restricted according to the rows around this row.
        const indentChange = self.rowObject.indent(indentDiff);
        // Update table and pointer indentations.
        self.dragObject.indentPointerPos.x +=
          Math.abs(self.indentAmount) * indentChange * self.rtl;
        self.indentCount = Math.max(self.indentCount, self.rowObject.indents);
      }

      return false;
    }
  };

  /**
   * Pointerup behavior.
   *
   * @param {MouseEvent|PointerEvent|Touch} event
   *   The pointer event.
   * @param {Drupal.tableDrag} self
   *   The tableDrag instance.
   */
  Drupal.tableDrag.prototype.dropRow = function (event, self) {
    let droppedRow;

    // Drop row functionality.
    if (self.rowObject !== null) {
      droppedRow = self.rowObject.element;
      // The row is already in the right place so we just release it.
      if (self.rowObject.changed === true) {
        // Update the fields in the dropped row.
        self.updateFields(droppedRow);

        // If a setting exists for affecting the entire group, update all the
        // fields in the entire dragged group.
        Object.keys(self.tableSettings || {}).forEach((group) => {
          const rowSettings = self.rowSettings(group, droppedRow);
          if (rowSettings.relationship === 'group') {
            Object.keys(self.rowObject.children || {}).forEach((n) => {
              self.updateField(self.rowObject.children[n], group);
            });
          }
        });

        self.rowObject.markChanged();
        if (self.changed === false) {
          self.rowObject.addChangedWarning();
          self.changed = true;
        }
      }

      if (self.indentEnabled) {
        self.rowObject.removeIndentClasses();
      }
      if (self.oldRowElement) {
        self.oldRowElement.classList.remove('drag-previous');
      }
      droppedRow.classList.remove('drag');
      droppedRow.classList.add('drag-previous');
      self.oldRowElement = droppedRow;
      self.onDrop();
      self.rowObject = null;
    }

    // Functionality specific only to pointerup events.
    if (self.dragObject !== null) {
      self.dragObject = null;
      document.body.classList.remove(self.dragOrientation);
      clearInterval(self.scrollInterval);
    }
  };

  /**
   * Get the coordinates from the event (allowing for browser differences).
   *
   * @param {MouseEvent|PointerEvent|Touch} event
   *   The pointer event.
   *
   * @return {object}
   *   An object with `x` and `y` keys indicating the position.
   */
  Drupal.tableDrag.prototype.pointerCoords = function (event) {
    if (event.pageX || event.pageY) {
      return { x: event.pageX, y: event.pageY };
    }
    return {
      x: event.clientX + document.body.scrollLeft - document.body.clientLeft,
      y: event.clientY + document.body.scrollTop - document.body.clientTop,
    };
  };

  /**
   * Get the event offset from the target element.
   *
   * Given a target element and a pointer event, get the event offset from that
   * element. To do this we need the element's position and the target position.
   *
   * @param {HTMLElement} target
   *   The target HTML element.
   * @param {MouseEvent|PointerEvent|Touch} event
   *   The pointer event.
   *
   * @return {object}
   *   An object with `x` and `y` keys indicating the position.
   */
  Drupal.tableDrag.prototype.getPointerOffset = function (target, event) {
    const docPos = offset(target);
    const pointerPos = this.pointerCoords(event);
    return { x: pointerPos.x - docPos.left, y: pointerPos.y - docPos.top };
  };

  /**
   * Find the row the mouse is currently over.
   *
   * This row is then taken and swapped with the one being dragged.
   *
   * @param {number} x
   *   The x coordinate of the mouse on the page (not the screen).
   * @param {number} y
   *   The y coordinate of the mouse on the page (not the screen).
   *
   * @return {*}
   *   The drop target row, if found.
   */
  Drupal.tableDrag.prototype.findDropTargetRow = function (x, y) {
    const rows = [...this.table.tBodies[0].rows].filter(
      Drupal.elementIsVisible,
    );
    for (let n = 0; n < rows.length; n++) {
      const row = rows[n];
      const rowY = offset(row).top;
      let rowHeight;
      // Because Safari does not report offsetHeight on table rows, but does on
      // table cells, grab the firstChild of the row and use that instead.
      // http://jacob.peargrove.com/blog/2006/technical/table-row-offsettop-bug-in-safari.
      if (row.offsetHeight === 0) {
        rowHeight = parseInt(row.firstChild.offsetHeight, 10) / 2;
      }
      // Other browsers.
      else {
        rowHeight = parseInt(row.offsetHeight, 10) / 2;
      }

      // Because we always insert before, we need to offset the height a bit.
      if (y > rowY - rowHeight && y < rowY + rowHeight) {
        if (this.indentEnabled) {
          // Check that this row is not a child of the row being dragged.
          if (
            Object.keys(this.rowObject.group).some(
              (o) => this.rowObject.group[o] === row,
            )
          ) {
            return null;
          }
        }
        // Do not allow a row to be swapped with itself.
        else if (row === this.rowObject.element) {
          return null;
        }

        // Check that swapping with this row is allowed.
        if (!this.rowObject.isValidSwap(row)) {
          return null;
        }

        return row;
      }
    }
    return null;
  };

  /**
   * After the row is dropped, update the table fields.
   *
   * @param {HTMLElement} changedRow
   *   DOM object for the row that was just dropped.
   */
  Drupal.tableDrag.prototype.updateFields = function (changedRow) {
    Object.keys(this.tableSettings || {}).forEach((group) => {
      // Each group may have a different setting for relationship, so we find
      // the source rows for each separately.
      this.updateField(changedRow, group);
    });
  };

  /**
   * After the row is dropped, update a single table field.
   *
   * @param {HTMLElement} changedRow
   *   DOM object for the row that was just dropped.
   * @param {string} group
   *   The settings group on which field updates will occur.
   */
  Drupal.tableDrag.prototype.updateField = function (changedRow, group) {
    let rowSettings = this.rowSettings(group, changedRow);
    let sourceRow;
    let useSibling;
    // Set the row as its own target.
    if (
      rowSettings.relationship === 'self' ||
      rowSettings.relationship === 'group'
    ) {
      sourceRow = changedRow;
    }
    // Siblings are easy, check previous and next rows.
    else if (rowSettings.relationship === 'sibling') {
      const previousRow = adjacentRow(
        changedRow,
        'previousElementSibling',
        'tr:first-of-type',
      );
      const nextRow = adjacentRow(
        changedRow,
        'nextElementSibling',
        'tr:first-of-type',
      );
      sourceRow = changedRow;
      if (
        previousRow?.matches('.draggable') &&
        previousRow.querySelector(`.${CSS.escape(group)}`)
      ) {
        if (this.indentEnabled) {
          if (
            previousRow.querySelectorAll('.js-indentations').length ===
            changedRow.querySelectorAll('.js-indentations').length
          ) {
            sourceRow = previousRow;
          }
        } else {
          sourceRow = previousRow;
        }
      } else if (
        nextRow?.matches('.draggable') &&
        nextRow.querySelector(`.${CSS.escape(group)}`)
      ) {
        if (this.indentEnabled) {
          if (
            nextRow.querySelectorAll('.js-indentations').length ===
            changedRow.querySelectorAll('.js-indentations').length
          ) {
            sourceRow = nextRow;
          }
        } else {
          sourceRow = nextRow;
        }
      }
    }
    // Parents, look up the tree until we find a field not in this group.
    // Go up as many parents as indentations in the changed row.
    else if (rowSettings.relationship === 'parent') {
      let previousRow = adjacentRow(changedRow, 'previousElementSibling');
      while (
        previousRow &&
        previousRow.querySelectorAll('.js-indentation').length >=
          this.rowObject.indents
      ) {
        previousRow = adjacentRow(previousRow, 'previousElementSibling');
      }
      // If we found a row.
      if (previousRow) {
        sourceRow = previousRow;
      }
      // Otherwise we went all the way to the left of the table without finding
      // a parent, meaning this item has been placed at the root level.
      else {
        // Use the first row in the table as source, because it's guaranteed to
        // be at the root level. Find the first item, then compare this row
        // against it as a sibling.
        sourceRow = this.table.querySelector('tr.draggable:first-of-type');
        if (sourceRow === this.rowObject.element) {
          sourceRow = adjacentRow(
            this.rowObject.group.at(-1),
            'nextElementSibling',
            'tr.draggable',
          );
        }
        useSibling = true;
      }
    }

    // Because we may have moved the row from one category to another,
    // take a look at our sibling and borrow its sources and targets.
    this.copyDragClasses(sourceRow, changedRow, group);
    rowSettings = this.rowSettings(group, changedRow);

    // In the case that we're looking for a parent, but the row is at the top
    // of the tree, copy our sibling's values.
    if (useSibling) {
      rowSettings.relationship = 'sibling';
      rowSettings.source = rowSettings.target;
    }

    const targetClass = `.${CSS.escape(rowSettings.target)}`;
    const targetElement = changedRow.querySelector(targetClass);

    // Check if a target element exists in this row.
    if (targetElement) {
      const sourceClass = `.${CSS.escape(rowSettings.source)}`;
      const sourceElement = (
        sourceRow === undefined ? document : sourceRow
      )?.querySelector(sourceClass);
      switch (rowSettings.action) {
        case 'depth':
          // Get the depth of the target row.
          targetElement.value =
            sourceElement?.closest('tr')?.querySelectorAll('.js-indentation')
              .length ?? 0;
          break;

        case 'match':
          // Update the value.
          if (sourceElement) {
            targetElement.value = sourceElement.value;
          }
          break;

        case 'order': {
          const siblings = this.rowObject.findSiblings(rowSettings);
          const siblingTargets = descendants(siblings, targetClass);
          if (targetElement.tagName === 'SELECT') {
            // Assign the last option to any rows beyond the available values.
            const values = [...targetElement.options].map(
              (option) => option.value,
            );
            const maxVal = values.at(-1);
            siblingTargets.forEach((target, index) => {
              target.value = values[index] ?? maxVal;
            });
          } else {
            // Assume a numeric input field.
            const weight =
              parseInt(siblings[0]?.querySelector(targetClass)?.value, 10) || 0;
            siblingTargets.forEach((target, index) => {
              target.value = weight + index;
            });
          }
          break;
        }
      }
    }
  };

  /**
   * Copy all tableDrag related classes from one row to another.
   *
   * Copy all special tableDrag classes from one row's form elements to a
   * different one, removing any special classes that the destination row
   * may have had.
   *
   * @param {HTMLElement} sourceRow
   *   The element for the source row.
   * @param {HTMLElement} targetRow
   *   The element for the target row.
   * @param {string} group
   *   The group selector.
   */
  Drupal.tableDrag.prototype.copyDragClasses = function (
    sourceRow,
    targetRow,
    group,
  ) {
    const sourceElement = sourceRow?.querySelector(`.${CSS.escape(group)}`);
    const targetElement = targetRow.querySelector(`.${CSS.escape(group)}`);
    if (sourceElement && targetElement) {
      targetElement.className = sourceElement.className;
    }
  };

  /**
   * Check the suggested scroll of the table.
   *
   * @param {number} cursorY
   *   The Y position of the cursor.
   *
   * @return {number}
   *   The suggested scroll.
   */
  Drupal.tableDrag.prototype.checkScroll = function (cursorY) {
    const de = document.documentElement;
    const b = document.body;

    const windowHeight =
      window.innerHeight ||
      (de.clientHeight && de.clientWidth !== 0
        ? de.clientHeight
        : b.offsetHeight);
    this.windowHeight = windowHeight;
    const scrollY = window.scrollY;
    this.scrollY = scrollY;
    const trigger = this.scrollSettings.trigger;
    let delta = 0;

    // Return a scroll speed relative to the edge of the screen.
    if (cursorY - scrollY > windowHeight - trigger) {
      delta = trigger / (windowHeight + scrollY - cursorY);
      delta = delta > 0 && delta < trigger ? delta : trigger;
      return delta * this.scrollSettings.amount;
    }
    if (cursorY - scrollY < trigger) {
      delta = trigger / (cursorY - scrollY);
      delta = delta > 0 && delta < trigger ? delta : trigger;
      return -delta * this.scrollSettings.amount;
    }
  };

  /**
   * Set the scroll for the table.
   *
   * @param {number} scrollAmount
   *   The amount of scroll to apply to the window.
   */
  Drupal.tableDrag.prototype.setScroll = function (scrollAmount) {
    const self = this;

    this.scrollInterval = setInterval(() => {
      // Update the scroll values stored in the object.
      self.checkScroll(self.currentPointerCoords.y);
      const aboveTable = self.scrollY > self.table.topY;
      const belowTable = self.scrollY + self.windowHeight < self.table.bottomY;
      if (
        (scrollAmount > 0 && belowTable) ||
        (scrollAmount < 0 && aboveTable)
      ) {
        window.scrollBy(0, scrollAmount);
      }
    }, this.scrollSettings.interval);
  };

  /**
   * Command to restripe table properly.
   */
  Drupal.tableDrag.prototype.restripeTable = function () {
    [...this.table.querySelectorAll(draggableRows)]
      .filter(Drupal.elementIsVisible)
      .forEach((row, index) => {
        row.classList.toggle('even', index % 2 === 1);
        row.classList.toggle('odd', index % 2 === 0);
      });
  };

  /**
   * Stub function. Allows a custom handler when a row begins dragging.
   *
   * @return {null}
   *   Returns null when the stub function is used.
   */
  Drupal.tableDrag.prototype.onDrag = function () {
    return null;
  };

  /**
   * Stub function. Allows a custom handler when a row is dropped.
   *
   * @return {null}
   *   Returns null when the stub function is used.
   */
  Drupal.tableDrag.prototype.onDrop = function () {
    return null;
  };

  /**
   * Constructor to make a new object to manipulate a table row.
   *
   * @param {HTMLElement} tableRow
   *   The DOM element for the table row we will be manipulating.
   * @param {string} method
   *   The method in which this row is being moved. Either 'keyboard' or
   *   'mouse'.
   * @param {boolean} indentEnabled
   *   Whether the containing table uses indentations. Used for optimizations.
   * @param {number} maxDepth
   *   The maximum amount of indentations this row may contain.
   * @param {boolean} addClasses
   *   Whether we want to add classes to this row to indicate child
   *   relationships.
   */
  Drupal.tableDrag.prototype.row = function (
    tableRow,
    method,
    indentEnabled,
    maxDepth,
    addClasses,
  ) {
    this.element = tableRow;
    this.method = method;
    this.group = [tableRow];
    this.groupDepth = tableRow?.querySelectorAll('.js-indentation').length ?? 0;
    this.changed = false;
    this.table = tableRow?.closest('table') ?? undefined;
    this.indentEnabled = indentEnabled;
    this.maxDepth = maxDepth;
    // Direction the row is being moved.
    this.direction = '';
    if (this.indentEnabled && tableRow) {
      this.indents = tableRow.querySelectorAll('.js-indentation').length;
      this.children = this.findChildren(addClasses);
      this.group = this.group.concat(this.children);
      // Find the depth of this entire group.
      for (let n = 0; n < this.group.length; n++) {
        this.groupDepth = Math.max(
          this.group[n].querySelectorAll('.js-indentation').length,
          this.groupDepth,
        );
      }
    }
  };

  /**
   * Adds a warning above the table informing users they must save changes.
   */
  Drupal.tableDrag.prototype.row.prototype.addChangedWarning = function () {
    // Do not add the changed warning if one is already present.
    if (!this.table.parentElement.querySelector('.tabledrag-changed-warning')) {
      const form = this.table.closest('form');
      const content = tableDrag.themeContent(
        Drupal.theme('tableDragChangedWarning'),
      );
      const warning =
        content instanceof Element ? content : content?.firstElementChild;
      if (warning) {
        this.table.before(content);
      }
      // Rebuilt tables show the warning immediately.
      if (warning && !form?.hasAttribute('data-tabledrag-save-warning')) {
        Drupal.trackAnimation(
          warning.animate([{ opacity: 0 }, { opacity: 1 }], {
            duration: 600,
            easing: 'ease-in-out',
          }),
        );
      }

      // The form survives table rebuilds.
      form?.setAttribute('data-tabledrag-save-warning', 'true');
    }
  };

  /**
   * Find all children of rowObject by indentation.
   *
   * @param {boolean} addClasses
   *   Whether we want to add classes to this row to indicate child
   *   relationships.
   *
   * @return {Array}
   *   An array of children of the row.
   */
  Drupal.tableDrag.prototype.row.prototype.findChildren = function (
    addClasses,
  ) {
    const parentIndentation = this.indents;
    let currentRow = adjacentRow(
      this.element,
      'nextElementSibling',
      'tr.draggable',
    );
    const rows = [];

    while (currentRow) {
      const indents = currentRow.querySelectorAll('.js-indentation');
      // A greater indentation indicates this is a child.
      if (indents.length <= parentIndentation) {
        break;
      }
      rows.push(currentRow);
      if (addClasses) {
        indents.forEach((indent, index) => {
          if (index === parentIndentation) {
            if (rows.length === 1) {
              indent.classList.add('tree-child-first');
            }
            indent.classList.add('tree-child');
          } else if (index > parentIndentation) {
            indent.classList.add('tree-child-horizontal');
          }
        });
      }
      currentRow = adjacentRow(
        currentRow,
        'nextElementSibling',
        'tr.draggable',
      );
    }
    if (addClasses && rows.length) {
      rows
        .at(-1)
        .querySelectorAll(`.js-indentation:nth-child(${parentIndentation + 1})`)
        .forEach((indent) => indent.classList.add('tree-child-last'));
    }
    return rows;
  };

  /**
   * Ensure that two rows are allowed to be swapped.
   *
   * @param {HTMLElement} row
   *   DOM object for the row being considered for swapping.
   *
   * @return {boolean}
   *   Whether the swap is a valid swap or not.
   */
  Drupal.tableDrag.prototype.row.prototype.isValidSwap = function (row) {
    if (this.indentEnabled) {
      let prevRow;
      let nextRow;
      if (this.direction === 'down') {
        prevRow = row;
        nextRow = adjacentRow(row, 'nextElementSibling');
      } else {
        prevRow = adjacentRow(row, 'previousElementSibling');
        nextRow = row;
      }
      this.interval = this.validIndentInterval(prevRow, nextRow);

      // We have an invalid swap if the valid indentations interval is empty.
      if (this.interval.min > this.interval.max) {
        return false;
      }
    }

    // Do not let an un-draggable first row have anything put before it.
    if (this.table.tBodies[0].rows[0] === row && !row.matches('.draggable')) {
      return false;
    }

    return true;
  };

  /**
   * Perform the swap between two rows.
   *
   * @param {string} position
   *   Whether the swap will occur 'before' or 'after' the given row.
   * @param {HTMLElement} row
   *   DOM element what will be swapped with the row group.
   */
  Drupal.tableDrag.prototype.row.prototype.swap = function (position, row) {
    // Makes sure only DOM object are passed to Drupal.detachBehaviors().
    this.group.forEach((row) => {
      Drupal.detachBehaviors(row, drupalSettings, 'move');
    });
    row[position](...this.group);
    // Makes sure only DOM object are passed to Drupal.attachBehaviors()s.
    this.group.forEach((row) => {
      Drupal.attachBehaviors(row, drupalSettings);
    });
    this.changed = true;
    this.onSwap(row);
  };

  /**
   * Determine the valid indentations interval for the row at a given position.
   *
   * @param {?HTMLElement} prevRow
   *   DOM object for the row before the tested position
   *   (or null for first position in the table).
   * @param {?HTMLElement} nextRow
   *   DOM object for the row after the tested position
   *   (or null for last position in the table).
   *
   * @return {object}
   *   An object with the keys `min` and `max` to indicate the valid indent
   *   interval.
   */
  Drupal.tableDrag.prototype.row.prototype.validIndentInterval = function (
    prevRow,
    nextRow,
  ) {
    let maxIndent;

    // Minimum indentation:
    // Do not orphan the next row.
    const minIndent = nextRow
      ? nextRow.querySelectorAll('.js-indentation').length
      : 0;

    // Maximum indentation:
    if (
      !prevRow ||
      !prevRow.matches('.draggable') ||
      this.element.matches('.tabledrag-root')
    ) {
      // Do not indent:
      // - the first row in the table,
      // - rows dragged below a non-draggable row,
      // - 'root' rows.
      maxIndent = 0;
    } else {
      // Do not go deeper than as a child of the previous row.
      maxIndent =
        prevRow.querySelectorAll('.js-indentation').length +
        (prevRow.matches('.tabledrag-leaf') ? 0 : 1);

      // Limit by the maximum allowed depth for the table.
      if (this.maxDepth) {
        maxIndent = Math.min(
          maxIndent,
          this.maxDepth - (this.groupDepth - this.indents),
        );
      }
    }

    return { min: minIndent, max: maxIndent };
  };

  /**
   * Indent a row within the legal bounds of the table.
   *
   * @param {number} indentDiff
   *   The number of additional indentations proposed for the row (can be
   *   positive or negative). This number will be adjusted to nearest valid
   *   indentation level for the row.
   *
   * @return {number}
   *   The number of indentations applied.
   */
  Drupal.tableDrag.prototype.row.prototype.indent = function (indentDiff) {
    // Determine the valid indentations interval if not available yet.
    if (!this.interval) {
      const prevRow = adjacentRow(this.element, 'previousElementSibling');
      const nextRow = adjacentRow(this.group.at(-1), 'nextElementSibling');
      this.interval = this.validIndentInterval(prevRow, nextRow);
    }

    // Adjust to the nearest valid indentation.
    let indent = this.indents + indentDiff;
    indent = Math.max(indent, this.interval.min);
    indent = Math.min(indent, this.interval.max);
    indentDiff = indent - this.indents;

    for (let n = 1; n <= Math.abs(indentDiff); n++) {
      // Add or remove indentations.
      if (indentDiff < 0) {
        descendants(this.group, '.js-indentation:first-of-type').forEach(
          (indentation) => indentation.remove(),
        );
        this.indents -= 1;
      } else {
        const markup = Drupal.theme('tableDragIndentation');
        const cells = descendants(this.group, 'td:first-of-type');
        cells.forEach((cell, index) => {
          const content = tableDrag.themeContent(markup);
          cell.prepend(
            index === cells.length - 1 ? content : content.cloneNode(true),
          );
        });
        this.indents += 1;
      }
    }
    if (indentDiff) {
      // Update indentation for this row.
      this.changed = true;
      this.groupDepth += indentDiff;
      this.onIndent();
    }

    return indentDiff;
  };

  /**
   * Find all siblings for a row.
   *
   * According to its subgroup or indentation. Note that the passed-in row is
   * included in the list of siblings.
   *
   * @param {object} rowSettings
   *   The field settings we're using to identify what constitutes a sibling.
   *
   * @return {Array}
   *   An array of siblings.
   */
  Drupal.tableDrag.prototype.row.prototype.findSiblings = function (
    rowSettings,
  ) {
    const siblings = [];
    ['previousElementSibling', 'nextElementSibling'].forEach((direction) => {
      for (let row = this.element[direction]; row; row = row[direction]) {
        const indentation = row.querySelectorAll('.js-indentation').length;
        if (!this.indentEnabled || indentation === this.indents) {
          siblings.push(row);
        } else if (indentation < this.indents) {
          // No need to keep looking for siblings when we get to a parent.
          break;
        }
      }
      // The preceding siblings were collected in reverse order.
      if (direction === 'previousElementSibling') {
        siblings.reverse();
        siblings.push(this.element);
      }
    });
    return siblings;
  };

  /**
   * Remove indentation helper classes from the current row group.
   */
  Drupal.tableDrag.prototype.row.prototype.removeIndentClasses = function () {
    descendants(this.children || [], '.js-indentation').forEach((indentation) =>
      indentation.classList.remove(
        'tree-child',
        'tree-child-first',
        'tree-child-last',
        'tree-child-horizontal',
      ),
    );
  };

  /**
   * Add an asterisk or other marker to the changed row.
   */
  Drupal.tableDrag.prototype.row.prototype.markChanged = function () {
    const cells = [
      ...(this.element?.querySelectorAll('td:first-of-type') ?? []),
    ];
    if (!cells.some((cell) => cell.querySelector('abbr.tabledrag-changed'))) {
      const marker = tableDrag.themeContent(
        Drupal.theme('tableDragChangedMarker'),
      );
      cells.forEach((cell, index) => {
        if (marker instanceof Node) {
          cell.append(
            index === cells.length - 1 ? marker : marker.cloneNode(true),
          );
        }
      });
    }
    Drupal.tableDrag[this.table.id].changedRowIds.add(this.element.id);
  };

  /**
   * Stub function. Allows a custom handler when a row is indented.
   *
   * @return {null}
   *   Returns null when the stub function is used.
   */
  Drupal.tableDrag.prototype.row.prototype.onIndent = function () {
    return null;
  };

  /**
   * Stub function. Allows a custom handler when a row is swapped.
   *
   * @param {HTMLElement} swappedRow
   *   The element for the swapped row.
   *
   * @return {null}
   *   Returns null when the stub function is used.
   */
  Drupal.tableDrag.prototype.row.prototype.onSwap = function (swappedRow) {
    return null;
  };

  Object.assign(
    Drupal.theme,
    /** @lends Drupal.theme */ {
      /**
       * @return {string}
       *  Markup for the marker.
       */
      tableDragChangedMarker() {
        return `<abbr class="warning tabledrag-changed" title="${Drupal.t(
          'Changed',
        )}">*</abbr>`;
      },

      /**
       * @return {string}
       *   Markup for the indentation.
       */
      tableDragIndentation() {
        return '<div class="js-indentation indentation">&nbsp;</div>';
      },

      /**
       * @return {string}
       *   Markup for the warning.
       */
      tableDragChangedWarning() {
        return `<div class="tabledrag-changed-warning messages messages--warning" role="alert">${Drupal.theme(
          'tableDragChangedMarker',
        )} ${Drupal.t('You have unsaved changes.')}</div>`;
      },

      /**
       * The button for toggling table row weight visibility.
       *
       * @return {string}
       *   HTML markup for the weight toggle button and its container.
       */
      tableDragToggle: () =>
        `<div class="tabledrag-toggle-weight-wrapper" data-drupal-selector="tabledrag-toggle-weight-wrapper">
            <button type="button" class="link tabledrag-toggle-weight" data-drupal-selector="tabledrag-toggle-weight"></button>
            </div>`,

      /**
       * The contents of the toggle weight button.
       *
       * @param {boolean} show
       *   If the table weights are currently displayed.
       *
       * @return {string}
       *  HTML markup for the weight toggle button content.s
       */
      toggleButtonContent: (show) =>
        show ? Drupal.t('Hide row weights') : Drupal.t('Show row weights'),

      /**
       * @return {string}
       *   HTML markup for a tableDrag handle.
       */
      tableDragHandle(dragOrientation = 'drag') {
        const title =
          dragOrientation === 'drag-y'
            ? Drupal.t('Change order')
            : Drupal.t('Move in any direction');
        return `<a href="#" title="${title}"
        class="tabledrag-handle"><div class="handle"></div></a>`;
      },
    },
  );
  /**
   * Converts theme markup to nodes for insertion.
   *
   * @param {string|Node|Node[]} markup
   *   Theme output.
   * @return {Node}
   *   The nodes to insert.
   */
  Drupal.tableDrag.themeContent = function (markup) {
    if (typeof markup === 'string') {
      const template = document.createElement('template');
      template.innerHTML = markup.trim();
      return template.content;
    }
    if (Array.isArray(markup)) {
      const fragment = document.createDocumentFragment();
      fragment.append(...markup);
      return fragment;
    }
    return markup;
  };
  const tableDrag = Drupal.tableDrag;
  tableInteraction.tableDragConstructor = tableDrag;
  const adoptEventOverrides = tableInteraction.installEventOverrides(
    Drupal.tableDrag.prototype,
    {
      dragStart: 0,
      dragRow: 0,
      dropRow: 0,
      pointerCoords: 0,
      getPointerOffset: 1,
    },
  );
  document.dispatchEvent(new Event('drupal:tabledrag-ready'));
})(Drupal, drupalSettings, once);
