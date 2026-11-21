/**
 * @file
 * Shows or hides the optional text summary.
 */

((Drupal) => {
  Drupal.behaviors.textSummary = {
    attach(context) {
      once('text-summary', '.js-text-summary', context).forEach((summary) => {
        const widget = summary.closest('.js-text-format-wrapper');
        const summaryWrapper = widget?.querySelector(
          '.js-text-summary-wrapper',
        );
        const summaryLabel = summaryWrapper?.querySelector('label');
        const full = widget?.querySelector(':scope > .js-form-type-textarea');
        if (!summaryLabel || !full) {
          return;
        }
        let fullLabel = full.querySelector('label');

        // Multi-value fields can omit the label for the full text field.
        if (!fullLabel) {
          fullLabel = document.createElement('label');
          full.prepend(fullLabel);
        }

        // Keep the original label visually hidden without hiding the toggle.
        if (fullLabel.classList.contains('visually-hidden')) {
          const content = document.createElement('span');
          content.className = 'visually-hidden';
          content.append(...fullLabel.childNodes);
          fullLabel.append(content);
          fullLabel.classList.remove('visually-hidden');
        }

        const template = document.createElement('template');
        template.innerHTML = Drupal.theme(
          'textEditSummaryButton',
          Drupal.t('Hide summary'),
        );
        const link = template.content.firstElementChild;
        const button = link.querySelector('button');
        const display = summaryWrapper.style.display;
        let visible = true;
        const toggle = () => {
          visible = !visible;
          summaryWrapper.style.display = visible ? display : 'none';
          button.innerHTML = visible
            ? Drupal.t('Hide summary')
            : Drupal.t('Edit summary');
          (visible ? summaryLabel : fullLabel).append(link);
        };
        link.addEventListener('click', (event) => {
          event.preventDefault();
          toggle();
        });
        summaryLabel.append(link);
        if (summary.value === '') {
          toggle();
        }
      });
    },
  };

  Drupal.theme.textEditSummaryButton = (title) =>
    `<span class="field-edit-link"> (<button type="button" class="link link-edit-summary">${title}</button>)</span>`;
})(Drupal);
