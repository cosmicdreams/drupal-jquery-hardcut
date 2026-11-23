/**
 * @file
 * User permission page behaviors.
 */

(function (Drupal, debounce) {
  /**
   * Shows checked and disabled checkboxes for inherited permissions.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches functionality to the permissions table.
   */
  Drupal.behaviors.permissions = {
    attach() {
      const [table] = once('permissions', 'table#permissions');
      if (!table) {
        return;
      }

      // Create fake checkboxes. We use fake checkboxes instead of reusing
      // the existing checkboxes here because new checkboxes don't alter the
      // submitted form. If we'd automatically check existing checkboxes, the
      // permission table would be polluted with redundant entries. This is
      // deliberate, but desirable when we automatically check them.
      const template = document.createElement('template');
      template.innerHTML = Drupal.theme('checkbox');
      const fakeCheckbox = template.content.firstElementChild;
      fakeCheckbox.classList.remove('form-checkbox');
      fakeCheckbox.classList.add('fake-checkbox', 'js-fake-checkbox');
      fakeCheckbox.setAttribute('disabled', 'disabled');
      fakeCheckbox.setAttribute('checked', 'checked');
      fakeCheckbox.title = Drupal.t(
        'This permission is inherited from the authenticated user role.',
      );

      /**
       * Process each table row to create fake checkboxes.
       *
       * @param {object} object
       * @param {HTMLElement} object.target
       */
      function tableRowProcessing({ target }) {
        once('permission-checkbox', target).forEach((checkbox) => {
          checkbox
            .closest('tr')
            .querySelectorAll(
              'input[type="checkbox"]:not(.js-rid-anonymous, .js-rid-authenticated)',
            )
            .forEach((check) => {
              check.classList.add('real-checkbox', 'js-real-checkbox');
              check.before(fakeCheckbox.cloneNode(true));
            });
        });
      }

      // An IntersectionObserver object is associated with each of the table
      // rows to activate checkboxes interactively as users scroll the page
      // up or down. This prevents processing all checkboxes on page load.
      const checkedCheckboxObserver = new IntersectionObserver(
        (entries, thisObserver) => {
          entries
            .filter((entry) => entry.isIntersecting)
            .forEach((entry) => {
              tableRowProcessing(entry);
              thisObserver.unobserve(entry.target);
            });
        },
        {
          rootMargin: '50%',
        },
      );

      // Select rows with checked authenticated role and attach an observer
      // to each.
      table
        .querySelectorAll(
          'tbody tr input[type="checkbox"].js-rid-authenticated:checked',
        )
        .forEach((checkbox) => checkedCheckboxObserver.observe(checkbox));

      // Create checkboxes only when necessary on click.
      Drupal.formEvents.listen(
        table,
        'click',
        tableRowProcessing,
        'input[type="checkbox"].js-rid-authenticated',
      );
    },
  };

  /**
   * Filters the permission list table by a text input search string.
   *
   * Text search input: input.table-filter-text
   * Target table:      input.table-filter-text[data-table]
   * Source text:       .table-filter-text-source
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.tableFilterByText = {
    attach(context, settings) {
      const [input] = once('table-filter-text', 'input.table-filter-text');
      if (!input) {
        return;
      }
      const tables = document.querySelectorAll(input.dataset.table);
      if (!tables.length) {
        return;
      }
      const rows = Array.from(tables).flatMap((table) =>
        Array.from(table.querySelectorAll('tbody tr')),
      );
      const visible = (row) => row.getClientRects().length > 0;
      const filterPermissionList = ({ target }) => {
        const query = target.value;
        // Match at the start of a word, ignoring case.
        const pattern = new RegExp('\\b' + query, 'i');
        rows.forEach((row) => {
          row.style.display = '';
        });
        if (query.length < 2) {
          return;
        }
        rows.forEach((row) => {
          const source = row.querySelector('.table-filter-text-source');
          if (source && !pattern.test(source.textContent)) {
            row.style.display = 'none';
          }
        });
        rows.filter(visible).forEach((row) => {
          if (row.querySelector('td.module')) {
            let next = row.nextElementSibling;
            while (next && getComputedStyle(next).display === 'none') {
              next = next.nextElementSibling;
            }
            if (!next || next.querySelector('td.module')) {
              row.style.display = 'none';
            }
          }
        });
        const count = rows
          .filter(visible)
          .reduce(
            (total, row) => total + row.querySelectorAll('.permission').length,
            0,
          );
        Drupal.announce(
          Drupal.formatPlural(
            count,
            '1 permission is available in the modified list.',
            '@count permissions are available in the modified list.',
          ),
        );
      };
      ['keyup', 'click'].forEach((type) => {
        Drupal.formEvents.listen(
          input,
          type,
          debounce(filterPermissionList, 200),
        );
      });
      Drupal.formEvents.listen(input, 'keydown', (event) => {
        if (
          event.key === 'Enter' ||
          event.which === 13 ||
          event.keyCode === 13
        ) {
          event.preventDefault();
          event.stopPropagation();
        }
      });
    },
  };
})(Drupal, Drupal.debounce);
