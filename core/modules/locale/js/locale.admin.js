/**
 * @file
 * Locale admin behavior.
 */

(function (Drupal) {
  const listeners = new WeakMap();

  /**
   * Marks altered rows and displays a warning above the table.
   */
  Drupal.behaviors.localeTranslateDirty = {
    attach() {
      once('localetranslatedirty', '#locale-translate-edit-form').forEach(
        (form) => {
          const namespace = 'localeTranslateDirty';
          const stopWarning = Drupal.form.listen(
            form,
            'formUpdated',
            namespace,
            (event, table) => {
              stopWarning();
              const template = document.createElement('template');
              template.innerHTML = Drupal.theme(
                'localeTranslateChangedWarning',
              );
              const marker = template.content.firstElementChild;
              table.classList.add('changed');
              if (!marker) {
                return;
              }
              const { opacity, transition } = marker.style;
              marker.style.opacity = '0';
              marker.style.transition = 'opacity 600ms ease-in-out';
              table.before(marker);

              // Commit the starting opacity before changing it to start the fade.
              marker.getBoundingClientRect();
              marker.style.opacity = opacity;
              Promise.allSettled(
                marker
                  .getAnimations()
                  .map(
                    (animation) => Drupal.trackAnimation(animation).finished,
                  ),
              ).then(() => {
                marker.style.transition = transition;
              });
            },
            'table',
          );
          const stopRows = Drupal.form.listen(
            form,
            'formUpdated',
            namespace,
            (event, row) => {
              row.classList.add('changed');
              if (once('localemark', row).length) {
                row
                  .querySelectorAll('td:first-child .js-form-item')
                  .forEach((item) => {
                    item.insertAdjacentHTML(
                      'beforeend',
                      Drupal.theme('localeTranslateChangedMarker'),
                    );
                  });
              }
            },
            'tr',
          );
          listeners.set(form, [stopWarning, stopRows]);
        },
      );
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        once
          .remove('localetranslatedirty', '#locale-translate-edit-form')
          .forEach((form) => {
            listeners.get(form)?.forEach((stop) => stop());
            listeners.delete(form);
          });
      }
    },
  };

  /**
   * Shows or hides details on the Available translation updates page.
   */
  Drupal.behaviors.hideUpdateInformation = {
    attach() {
      once('expand-updates', '#locale-translation-status-form').forEach(
        (form) => {
          const toggle = (event, description) => {
            if (event.keyCode && event.keyCode !== 13 && event.keyCode !== 32) {
              return;
            }
            event.preventDefault();
            const row = description.closest('tr');
            const expanded = row.classList.toggle('expanded');
            const prefix = row.querySelector(
              '.locale-translation-update__prefix',
            );
            if (prefix) {
              prefix.textContent = expanded
                ? Drupal.t('Hide description')
                : Drupal.t('Show description');
            }
          };
          form.querySelectorAll('tbody').forEach((body) => {
            ['click', 'keydown'].forEach((type) => {
              Drupal.form.listen(body, type, undefined, toggle, '.description');
            });
          });
          form.querySelectorAll('.requirements, .links').forEach((element) => {
            element.style.display = 'none';
          });
        },
      );
    },
  };

  Object.assign(
    Drupal.theme,
    /** @lends Drupal.theme */ {
      /**
       * Creates markup for a changed translation marker.
       *
       * @return {string}
       *   Markup for the marker.
       */
      localeTranslateChangedMarker() {
        return `<abbr class="warning ajax-changed" title="${Drupal.t(
          'Changed',
        )}">*</abbr>`;
      },

      /**
       * Creates markup for the translation changed warning.
       *
       * @return {string}
       *   Markup for the warning.
       */
      localeTranslateChangedWarning() {
        return `<div class="clearfix messages messages--warning">${Drupal.theme(
          'localeTranslateChangedMarker',
        )} ${Drupal.t(
          'Changes made in this table will not be saved until the form is submitted.',
        )}</div>`;
      },
    },
  );
})(Drupal);
