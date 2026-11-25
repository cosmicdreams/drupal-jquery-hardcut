/**
 * @file
 * Block behaviors.
 */

(function (window, Drupal, once) {
  /**
   * Provide the summary information for the block settings vertical tabs.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches the behavior for the block settings summaries.
   */
  Drupal.behaviors.blockSettingsSummary = {
    attach() {
      // The form summary API is not available
      // on the Blocks administration page, so we need to make sure this
      // behavior is processed only if the form library is loaded.
      if (!Drupal.form) {
        return;
      }

      /**
       * Create a summary for checkboxes in the provided context.
       *
       * @param {Document|HTMLElement} context
       *   A context where one would find checkboxes to summarize.
       *
       * @return {string}
       *   A string with the summary.
       */
      function checkboxesSummary(context) {
        const values = Array.from(
          context.querySelectorAll('input[type="checkbox"]:checked + label'),
          (label) => label.innerHTML,
        );
        if (!values.length) {
          values.push(Drupal.t('Not restricted'));
        }
        return values.join(', ');
      }

      Drupal.form.setSummary(
        document.querySelectorAll(
          '[data-drupal-selector="edit-visibility-node-type"], [data-drupal-selector="edit-visibility-entity-bundlenode"], [data-drupal-selector="edit-visibility-language"], [data-drupal-selector="edit-visibility-user-role"], [data-drupal-selector="edit-visibility-response-status"]',
        ),
        checkboxesSummary,
      );

      Drupal.form.setSummary(
        document.querySelectorAll(
          '[data-drupal-selector="edit-visibility-request-path"]',
        ),
        (context) => {
          const pages = context.querySelector(
            'textarea[name="visibility[request_path][pages]"]',
          );
          if (!pages?.value) {
            return Drupal.t('Not restricted');
          }

          return Drupal.t('Restricted to certain pages');
        },
      );
    },
  };

  /**
   * Move a block in the blocks table between regions via select list.
   *
   * This behavior is dependent on the tableDrag behavior, since it uses the
   * objects initialized in that behavior to update the row.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches the tableDrag behavior for blocks in block administration.
   */
  Drupal.behaviors.blockDrag = {
    attach(context) {
      const tableDrag = Drupal.tableDrag?.blocks;
      const table = tableDrag?.table;
      if (!table?.isConnected) {
        return;
      }

      const checkEmptyRegions = (rowObject) => {
        table.querySelectorAll('tr.region-message').forEach((message) => {
          if (
            message.previousElementSibling === rowObject.element &&
            (rowObject.method !== 'keyboard' || rowObject.direction === 'down')
          ) {
            rowObject.swap('after', message);
          }
          const populated = message.nextElementSibling?.matches('tr.draggable');
          message.classList.toggle('region-empty', !populated);
          message.classList.toggle('region-populated', Boolean(populated));
        });
      };

      const updateRegionFields = (row, regionField, region) => {
        if (!regionField.classList.contains(`block-region-${region}`)) {
          const weightField = row.querySelector('select.block-weight');
          const previous = weightField.className.replace(
            /([^ ]+[ ]+)*block-weight-([^ ]+)([ ]+[^ ]+)*/,
            '$2',
          );
          regionField.classList.remove(`block-region-${previous}`);
          regionField.classList.add(`block-region-${region}`);
          weightField.classList.remove(`block-weight-${previous}`);
          weightField.classList.add(`block-weight-${region}`);
          regionField.value = region;
        }
      };

      const updateLastPlaced = (rowObject) => {
        table
          .querySelectorAll('.color-success')
          .forEach((row) => row.classList.remove('color-success'));
        if (!rowObject.element.matches('.drag-previous')) {
          table
            .querySelectorAll('.drag-previous')
            .forEach((row) => row.classList.remove('drag-previous'));
        }
      };

      const updateBlockWeights = (region) => {
        let weight = -Math.round(
          table.querySelectorAll('.draggable').length / 2,
        );
        table
          .querySelectorAll(`.region-${CSS.escape(region)}-message`)
          .forEach((message) => {
            for (
              let row = message.nextElementSibling;
              row && !row.matches('.region-title');
              row = row.nextElementSibling
            ) {
              row.querySelectorAll('select.block-weight').forEach((field) => {
                weight += 1;
                field.value = weight;
              });
            }
          });
      };

      function onSwap() {
        checkEmptyRegions(this);
        updateLastPlaced(this);
      }
      tableDrag.row.prototype.onSwap = onSwap;

      function onDrop() {
        const row = this.rowObject.element;
        let regionRow = row.previousElementSibling;
        while (regionRow && !regionRow.matches('tr.region-message')) {
          regionRow = regionRow.previousElementSibling;
        }
        const region = regionRow.className.replace(
          /([^ ]+[ ]+)*region-([^ ]+)-message([ ]+[^ ]+)*/,
          '$2',
        );
        const regionField = row.querySelector('select.block-region-select');
        if (
          !Array.from(regionField.options).some(
            (option) => option.value === region,
          )
        ) {
          window.alert(Drupal.t('The block cannot be placed in this region.'));
          regionField.dispatchEvent(new Event('change', { bubbles: true }));
        }
        updateRegionFields(row, regionField, region);
        updateBlockWeights(region);
      }
      tableDrag.onDrop = onDrop;

      once(
        'block-region-select',
        'select.block-region-select',
        context,
      ).forEach((select) => {
        Drupal.formEvents.listen(select, 'change', () => {
          const row = select.closest('tr');
          tableDrag.rowObject = new tableDrag.row(row);
          const message = table.querySelector(
            `.region-${CSS.escape(select.value)}-message`,
          );
          let last = message;
          while (
            last.nextElementSibling &&
            !last.nextElementSibling.matches('.region-message, .region-title')
          ) {
            last = last.nextElementSibling;
          }
          last.after(row);
          updateRegionFields(row, select, select.value);
          updateBlockWeights(select.value);
          checkEmptyRegions(tableDrag.rowObject);
          updateLastPlaced(tableDrag.rowObject);
          if (!tableDrag.changed) {
            tableDrag.rowObject.addChangedWarning();
            tableDrag.changed = true;
          }
          select.blur();
        });
      });
    },
  };
})(window, Drupal, once);
