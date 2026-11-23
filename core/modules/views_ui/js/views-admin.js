/**
 * @file
 * Behaviors for the Views editor and add-view wizard.
 */

((Drupal, once) => {
  Drupal.behaviors.viewsUiEditView = {
    attach(context) {
      once(
        'views-sql-rewrite',
        '[data-drupal-selector="edit-query-options-disable-sql-rewrite"]',
        context,
      ).forEach((checkbox) => {
        const warning = checkbox.form.querySelector('.sql-rewrite-warning');
        const update = () =>
          warning?.classList.toggle('js-hide', !checkbox.checked);
        checkbox.addEventListener('change', update);
        update();
      });
    },
  };

  Drupal.behaviors.viewsUiAddView = {
    attach(context) {
      const groups = [
        {
          selector:
            '[id^="edit-page-title"], [id^="edit-block-title"], [id^="edit-page-link-properties-title"]',
        },
        { selector: '[id^="edit-page-path"]', path: true },
        {
          selector: '[id^="edit-page-feed-properties-path"]',
          path: true,
          suffix: '.xml',
        },
      ];
      const source = document.getElementById('edit-label');
      if (!source?.form) {
        return;
      }
      groups.forEach(({ selector, path, suffix = '' }, index) => {
        const targets = once(`views-fill-${index}`, selector, context);
        if (!targets.length) {
          return;
        }

        const populate = () => {
          const value = path
            ? source.value.toLowerCase().replace(/[^a-z0-9-]+/g, '-')
            : source.value;
          targets.filter((target) => target.isConnected).forEach((target) => {
            const max = target.maxLength;
            target.value =
              (max < 0 ? value : value.slice(0, Math.max(0, max - suffix.length))) +
              suffix;
          });
        };
        // Replace the previous listener when the wizard rebuilds its fields.
        const key = `views-fill-${index}`;
        source.removeEventListener('input', Drupal.ajax.elementData.get(source, key));
        targets.forEach((target) =>
          target.addEventListener(
            'focus',
            () => source.removeEventListener('input', populate),
            { once: true },
          ),
        );
        Drupal.ajax.elementData.set(source, key, populate);
        source.addEventListener('input', populate);
      });
    },
  };

  Drupal.behaviors.addItemForm = {
    attach(context) {
      const selector = 'form[id^="views-ui-add-handler-form"]';
      const forms = context.matches?.(selector)
        ? [context]
        : context.querySelectorAll(selector);
      once('views-ui-add-handler-form', forms).forEach((form) => {
        const search = form.querySelector(
          '[data-drupal-selector="edit-override-controls-options-search"]',
        );
        const category = form.querySelector(
          '[data-drupal-selector="edit-override-controls-group"]',
        );
        const selected = form.querySelector('.views-selected-options');
        const checked = new Map();
        const options = Array.from(form.querySelectorAll('.filterable-option'), (row) => {
          const title = row.querySelector('.title');
          const description = row.querySelector('.description');
          const checkbox = row.querySelector('input[type="checkbox"]');
          title.addEventListener('click', () => checkbox.click());
          const label = title.textContent.trim();
          if (checkbox.checked) {
            checked.set(checkbox, label);
          }
          checkbox.addEventListener('change', () => {
            if (checkbox.checked) {
              checked.set(checkbox, label);
            } else {
              checked.delete(checkbox);
            }
            updateSelected();
            notifyResize();
          });
          return { row, text: `${label} ${description.textContent}`.toLowerCase() };
        });

        function notifyResize() {
          form.dispatchEvent(new Event('dialogContentResize', { bubbles: true }));
        }

        function updateSelected() {
          selected.textContent = [...checked.values()].join(', ');
          selected.parentElement.hidden = !checked.size;
        }

        function filter() {
          const words = search?.value.toLowerCase().trim().split(/\s+/) ?? [];
          const group = category?.value;
          options.forEach(({ row, text }) => {
            row.hidden =
              (!!group && group !== 'all' && !row.classList.contains(group)) ||
              !words.every((word) => text.includes(word));
          });
          notifyResize();
        }

        search?.addEventListener('input', filter);
        search?.addEventListener('keydown', (event) => {
          if (event.key === 'Enter') {
            event.preventDefault();
          }
        });
        category?.addEventListener('change', filter);
        updateSelected();
        filter();
      });
    },
  };

  Drupal.behaviors.viewsUiRenderAddViewButton = {
    attach(context) {
      once(
        'views-ui-render-add-view-button',
        '#views-display-menu-tabs',
        context,
      ).forEach((tabs) => {
        const buttons = Array.from(tabs.parentElement.children).filter(
          (element) => element.matches('input.add-display, button.add-display'),
        );
        if (!buttons.length) {
          return;
        }
        const item = document.createElement('li');
        item.className = 'add';
        const trigger = document.createElement('a');
        const icon = document.createElement('span');
        icon.className = 'icon add';
        icon.setAttribute('aria-hidden', 'true');
        trigger.append(icon, Drupal.t('Add'));
        trigger.href = '#';
        trigger.setAttribute('role', 'button');
        trigger.setAttribute('aria-controls', 'views-add-display-options');
        const list = document.createElement('ul');
        list.id = 'views-add-display-options';
        list.className = 'action-list';
        buttons.forEach((button, index) => {
          const entry = document.createElement('li');
          entry.classList.toggle('first', index === 0);
          entry.classList.toggle('last', index === buttons.length - 1);
          button.value = button.dataset.drupalDropdownLabel;
          entry.append(button);
          list.append(entry);
        });
        function show(open) {
          item.classList.toggle('open', open);
          trigger.setAttribute('aria-expanded', String(open));
          list.hidden = !open;
        }
        show(false);
        item.append(trigger, list);
        tabs.append(item);
        trigger.addEventListener('click', (event) => {
          event.preventDefault();
          show(list.hidden);
        });
        item.addEventListener('mouseleave', () => {
          if (!list.contains(document.activeElement)) {
            show(false);
          }
        });
        item.addEventListener('focusout', (event) => {
          if (!item.contains(event.relatedTarget)) {
            show(false);
          }
        });
        item.addEventListener('keydown', (event) => {
          if (event.key === 'Escape' && !list.hidden) {
            show(false);
            trigger.focus();
            event.stopPropagation();
          }
          if (event.key === ' ' && event.target === trigger) {
            event.preventDefault();
            trigger.click();
          }
        });
      });
    },
  };

  Drupal.behaviors.viewsUiPreview = {
    attach(context) {
      const bucket = context.querySelector(
        '.views-display-column .views-ui-display-tab-bucket.argument',
      );
      const args = document.getElementById('preview-args');
      if (bucket && args) {
        args.parentElement.hidden = !bucket.querySelector('.views-display-setting a');
      }
    },
  };

  /**
   * Sets up filter group controls and updates them after tabledrag moves rows.
   */
  function rearrangeFilters(table, tableDrag) {
    const form = table.closest('form');
    const groups = [...table.querySelectorAll('.views-group-title')].map((title) => ({
      title,
      operator: title.querySelector('select'),
      cell: title.querySelector('.group-operator'),
      message: title.nextElementSibling,
    }));

    function redraw() {
      groups.forEach(({ operator, cell, message }) => {
        const rows = [];
        for (
          let row = message.nextElementSibling;
          row?.classList.contains('draggable');
          row = row.nextElementSibling
        ) {
          row.querySelector('.views-operator-label')?.remove();
          if (!row.hidden) {
            rows.push(row);
          }
        }
        cell.rowSpan = Math.max(2, rows.length + 1);
        message.classList.toggle('group-populated', !!rows.length);
        message.classList.toggle('group-empty', !rows.length);
        rows.slice(0, -1).forEach((row) => {
          const label = document.createElement('span');
          label.className = 'views-operator-label';
          label.textContent = operator.selectedOptions[0].textContent;
          row.cells[0].append(label);
        });
      });
    }

    // A visible action forwards to the form button's configured Ajax event.
    function addAction(button, text, className = '') {
      const link = document.createElement('a');
      link.href = '#';
      link.textContent = text;
      link.className = className;
      link.addEventListener('click', (event) => {
        event.preventDefault();
        button.dispatchEvent(
          new MouseEvent('mousedown', { bubbles: true, cancelable: true }),
        );
      });
      return link;
    }

    const add = form.querySelector('#views-add-group');
    const action = addAction(add, add.value);
    action.id = 'views-add-group-link';
    const actions = document.createElement('ul');
    actions.className = 'action-links';
    actions.append(document.createElement('li'));
    actions.firstElementChild.append(action);
    table.before(actions);
    table.querySelectorAll('.views-remove-group').forEach((button) => {
      button.before(addAction(button, Drupal.t('Remove group'), 'views-remove-group-link'));
    });

    const operatorWrapper = form.querySelector('.js-form-item-filter-groups-operator');
    if (operatorWrapper && groups.length > 1) {
      const operator = operatorWrapper.querySelector('select');
      operatorWrapper.querySelectorAll('label, .description').forEach((element) =>
        element.classList.add('visually-hidden'),
      );
      const selects = [operator];
      groups.slice(1).forEach(({ title }, index) => {
        const row = document.createElement('tr');
        row.className = 'filter-group-operator-row';
        const cell = row.insertCell();
        cell.colSpan = 5;
        if (index === 0) {
          cell.append(operatorWrapper);
        } else {
          const copy = operator.cloneNode(true);
          copy.removeAttribute('id');
          copy.removeAttribute('name');
          copy.setAttribute('aria-label', Drupal.t('Operator to use on all groups'));
          copy.value = operator.value;
          selects.push(copy);
          cell.append(copy);
        }
        title.before(row);
      });
      selects.forEach((select) => {
        select.addEventListener('change', () => {
          selects.forEach((other) => {
            other.value = select.value;
          });
        });
      });
    }

    // Use a table-specific row hook so nested or later tables are unaffected.
    const Row = tableDrag.row;
    tableDrag.row = class extends Row {
      onSwap() {
        const previous = this.element.previousElementSibling;
        if (previous && !previous.matches('.group-message, .draggable')) {
          let next = this.element.nextElementSibling;
          while (next && !next.matches('.group-message, .draggable')) {
            next = next.nextElementSibling;
          }
          if (next) {
            this.swap('after', next);
            return;
          }
        }
        redraw();
      }
    };

    tableDrag.onDrop = function () {
      const { element } = this.rowObject;
      let marker = element.previousElementSibling;
      while (marker && !marker.matches('.group-message')) {
        marker = marker.previousElementSibling;
      }
      const field = element.querySelector('select.views-group-select');
      if (marker && field) {
        const group = marker.id.slice('views-group-'.length);
        field.classList.remove(...[...field.classList].filter((name) =>
          name.startsWith('views-group-select-'),
        ));
        field.classList.add(`views-group-select-${group}`);
        field.value = group;
      }
      redraw();
    };

    groups.forEach(({ operator }) => operator.addEventListener('change', redraw));
    table.querySelectorAll('a.views-groups-remove-link').forEach((link) =>
      link.addEventListener('click', redraw),
    );
    redraw();
  }

  Drupal.behaviors.viewsFilterConfigSelectAll = {
    attach(context) {
      once('filterConfigSelectAll', '.js-form-item-options-value-all', context).forEach((item) => {
        const selectAll = item.querySelector('input[type="checkbox"]');
        const checkboxes = item.closest('.form-checkboxes').querySelectorAll(
          '.js-form-type-checkbox:not(.js-form-item-options-value-all) input[type="checkbox"]',
        );
        item.classList.remove('js-hide');
        item.hidden = false;
        selectAll.addEventListener('change', () => {
          checkboxes.forEach((checkbox) => {
            checkbox.checked = selectAll.checked;
          });
        });
        checkboxes.forEach((checkbox) =>
          checkbox.addEventListener('change', () => {
            if (!checkbox.checked) {
              selectAll.checked = false;
            }
          }),
        );
      });
    },
  };

  Drupal.behaviors.viewsRemoveIconClass = {
    attach(context) {
      once('dropbutton-icon', '.dropbutton', context).forEach((dropbutton) => {
        dropbutton.querySelectorAll('.icon').forEach((icon) => icon.classList.remove('icon'));
      });
    },
  };

  Drupal.behaviors.viewsUiCheckboxify = {
    attach(context) {
      once(
        'views-ui-checkboxify',
        '[data-drupal-selector="edit-options-expose-button-button"], [data-drupal-selector="edit-options-group-button-button"]',
        context,
      ).forEach((button) => {
        button.hidden = true;
        const parent = button.closest('div.views-expose, div.views-grouped');
        parent.querySelectorAll('.exposed-description, .grouped-description').forEach(
          (description) => { description.hidden = true; },
        );
        parent.querySelectorAll('input[type="checkbox"], input[type="radio"]').forEach((input) =>
          input.addEventListener('click', () => button.click()),
        );
      });
    },
  };

  Drupal.behaviors.viewsUiChangeDefaultWidget = {
    attach(context) {
      once(
        'views-default-widget',
        'input[name="options[group_info][multiple]"]',
        context,
      ).forEach((input) => {
        const update = () => {
          input.form.querySelectorAll('input.default-radios, td.any-default-radios-row')
            .forEach((element) => { element.parentElement.hidden = input.checked; });
          input.form.querySelectorAll('input.default-checkboxes')
            .forEach((element) => { element.parentElement.hidden = !input.checked; });
        };
        input.addEventListener('change', update);
        update();
      });
    },
  };

  Drupal.behaviors.viewsUiOverrideSelect = {
    attach(context) {
      once(
        'views-ui-override-button-text',
        '[data-drupal-selector="edit-override-dropdown"]',
        context,
      ).forEach((dropdown) => {
        const submit = dropdown.form.querySelector('[id^="edit-submit"]');
        if (!submit) {
          return;
        }
        const oldValue = submit.value;
        submit.addEventListener('mouseup', () => { submit.value = oldValue; });
        function update() {
          if (dropdown.value === 'default') {
            submit.value = Drupal.t('Apply (all displays)');
          } else if (dropdown.value === 'default_revert') {
            submit.value = Drupal.t('Revert to default');
          } else {
            submit.value = Drupal.t('Apply (this display)');
          }
          dropdown.closest('.drupal-dialog-content')?.dispatchEvent(
            new Event('dialogButtonsChange'),
          );
        }
        dropdown.addEventListener('change', update);
        update();
      });
    },
  };

  Drupal.behaviors.viewsUiHandlerRemoveLink = {
    attach(context) {
      once('views-remove', 'a.views-remove-link, a.display-remove-link', context).forEach((link) => {
        link.addEventListener('click', (event) => {
          event.preventDefault();
          const [prefix, id] = link.id.split('-remove-link-');
          document.getElementById(`${prefix}-row-${id}`).hidden = true;
          document.getElementById(`${prefix}-removed-${id}`).checked = true;
        });
      });
    },
  };

  Drupal.behaviors.viewsUiRearrangeFilter = {
    attach(context) {
      const drag = Drupal.tableDrag?.['views-rearrange-filters'];
      if (drag) {
        once('views-rearrange-filters', '#views-rearrange-filters', context)
          .forEach((table) => rearrangeFilters(table, drag));
      }
    },
  };
})(Drupal, once);
