/**
 * @file
 * Menu UI behaviors.
 */

(function (Drupal) {
  const overrides = new WeakSet();

  /**
   * Set a summary on the menu link form.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Find the form and set its summary.
   */
  Drupal.behaviors.menuUiDetailsSummaries = {
    attach(context) {
      Drupal.form.setSummary(
        context.querySelectorAll('.menu-link-form'),
        (context) => {
          if (
            context.querySelector('.js-form-item-menu-enabled input:checked')
          ) {
            return Drupal.checkPlain(
              context.querySelector('.js-form-item-menu-title input').value,
            );
          }

          return Drupal.t('Not in menu');
        },
      );
    },
  };

  /**
   * Automatically fill in a menu link title, if possible.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches change and keyup behavior for automatically filling out menu
   *   link titles.
   */
  Drupal.behaviors.menuUiLinkAutomaticTitle = {
    attach(context) {
      context.querySelectorAll('.menu-link-form').forEach((widget) => {
        const checkboxes = widget.querySelectorAll(
          '.js-form-item-menu-enabled input',
        );
        const linkTitles = context.querySelectorAll(
          '.js-form-item-menu-title input',
        );
        const titles = widget
          .closest('form')
          ?.querySelectorAll('.js-form-item-title-0-value input');
        const [checkbox] = checkboxes;
        const [linkTitle] = linkTitles;
        const title = titles?.[0];
        if (!checkbox || !linkTitle || !title) {
          return;
        }
        if (checkbox.checked && linkTitle.value) {
          linkTitles.forEach((field) => overrides.add(field));
        }
        linkTitles.forEach((field) => {
          Drupal.formEvents.listen(field, 'keyup', () => {
            linkTitles.forEach((input) => overrides.add(input));
          });
        });
        checkboxes.forEach((field) => {
          Drupal.formEvents.listen(field, 'change', () => {
            if (checkbox.checked) {
              if (!overrides.has(linkTitle)) {
                linkTitle.value = title.value;
              }
            } else {
              linkTitle.value = '';
              linkTitles.forEach((input) => overrides.delete(input));
            }
            const pane = checkbox.closest('.vertical-tabs-pane');
            if (pane) {
              Drupal.formEvents.publish(pane, 'summaryUpdated');
            }
            checkboxes.forEach((input) =>
              Drupal.formEvents.publish(input, 'formUpdated'),
            );
          });
        });
        titles.forEach((field) => {
          Drupal.formEvents.listen(field, 'keyup', () => {
            if (!overrides.has(linkTitle) && checkbox.checked) {
              linkTitle.value = title.value;
              linkTitles.forEach((input) =>
                Drupal.formEvents.publish(input, 'formUpdated'),
              );
            }
          });
        });
      });
    },
  };
})(Drupal);
