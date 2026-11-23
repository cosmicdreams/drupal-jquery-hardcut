/**
 * @file
 * Content Translation admin behaviors.
 */

((Drupal, drupalSettings) => {
  const inputTypes = ['input', 'select', 'textarea', 'button'];
  const input = `:is(${inputTypes.join(', ')})`;
  // The legacy event bridge also passes these selectors to jQuery delegation.
  const translatableInputs = (group) =>
    inputTypes
      .map((type) => `table .${group}-settings .translatable ${type}`)
      .join(', ');
  const bundleSelector = translatableInputs('bundle');
  const fieldSelector = translatableInputs('field');

  /**
   * Finds the following settings rows until the next group begins.
   */
  function following(row, boundary) {
    const rows = [];
    for (
      let next = row.nextElementSibling;
      next && !next.matches(boundary);
      next = next.nextElementSibling
    ) {
      rows.push(next);
    }
    return rows;
  }

  Drupal.behaviors.contentTranslationDependentOptions = {
    attach(context) {
      const settings = drupalSettings.contentTranslationDependentOptions;
      Object.entries(settings?.dependent_selectors || {}).forEach(
        ([name, columns]) => {
          const fields = Array.from(
            context.querySelectorAll(`input[name^="${CSS.escape(name)}"]`),
          );
          fields.forEach((field) => {
            Drupal.formEvents.listen(field, 'change', ({ target }) =>
              this.checkNative(fields, columns, target),
            );
          });
          this.checkNative(fields, columns);
        },
      );
    },

    /**
     * Applies dependent column settings using native elements.
     */
    checkNative(fields, dependentColumns, changed) {
      Object.values(dependentColumns || {}).forEach((column) => {
        const matched = changed
          ? [changed]
          : fields.filter((field) => field.value === column);
        if (matched[0]?.value === column && matched[0].checked) {
          fields.forEach((field) => {
            field.checked = true;
            if (!matched.includes(field)) {
              field.disabled = true;
            }
          });
        } else {
          fields.forEach((field) => {
            field.disabled = false;
          });
        }
      });
    },
  };

  Drupal.behaviors.contentTranslation = {
    attach(context) {
      once('translation-entity-admin-hide', bundleSelector, context).forEach(
        (checkbox) => {
          const bundle = checkbox.closest('.bundle-settings');
          const settings = following(bundle, '.bundle-settings');
          if (!checkbox.checked) {
            settings.forEach((row) => {
              row.style.display = 'none';
            });
          } else {
            settings
              .filter((row) => row.matches('.field-settings'))
              .forEach((row) => {
                if (row.querySelector(`.translatable ${input}:not(:checked)`)) {
                  following(row, ':not(.column-settings)').forEach((column) => {
                    column.style.display = 'none';
                  });
                }
              });
          }
        },
      );

      once('translation-entity-admin-bind', 'body').forEach((body) => {
        Drupal.formEvents.listen(
          body,
          'click',
          (event, checkbox) => {
            const bundle = checkbox.closest('.bundle-settings');
            const settings = following(bundle, '.bundle-settings');
            if (checkbox.checked) {
              bundle
                .querySelectorAll(
                  `.operations ${input}[name$="[language_alterable]"]`,
                )
                .forEach((setting) => {
                  setting.checked = true;
                });
              settings
                .filter((row) => row.matches('.field-settings'))
                .forEach((row) => {
                  row
                    .querySelectorAll(`.translatable ${input}`)
                    .forEach((setting) => {
                      setting.checked = true;
                    });
                });
            }
            settings.forEach((row) => {
              row.style.display = checkbox.checked ? '' : 'none';
            });
          },
          bundleSelector,
        );
        Drupal.formEvents.listen(
          body,
          'click',
          (event, checkbox) => {
            const field = checkbox.closest('.field-settings');
            following(field, '.field-settings, .bundle-settings').forEach(
              (column) => {
                column.style.display = checkbox.checked ? '' : 'none';
              },
            );
          },
          fieldSelector,
        );
      });
    },
  };
})(Drupal, drupalSettings);
