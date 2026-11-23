/**
 * @file
 * Integrates Drupal Ajax commands with native dialogs.
 */

(function (Drupal) {
  function syncButtons(content, dialog) {
    const originals = content.querySelectorAll(
      '.form-actions input[type=submit], .form-actions a.button, .form-actions a.action-link',
    );
    dialog.update({
      buttons: Array.from(originals, (button) => {
        button.style.display = 'none';
        return {
          text: button.value || button.textContent,
          class: button.className,
          'data-once': button.dataset.once,
          disabled: button.disabled,
          click() {
            if (button instanceof HTMLInputElement) {
              // Drupal Ajax submit controls can listen for mousedown.
              ['mousedown', 'mouseup'].forEach((type) =>
                button.dispatchEvent(new MouseEvent(type)),
              );
            }
            button.click();
          },
        };
      }),
    });
  }

  Drupal.behaviors.dialog = {
    attach(context) {
      const content = context.closest?.('.drupal-dialog-content');
      const dialog = content && Drupal.dialog.get(content);
      if (!dialog?.open) {
        return;
      }
      if (
        dialog.settings.drupalAutoButtons &&
        !dialog.settings.explicitButtons
      ) {
        syncButtons(content, dialog);
      }
      if (!dialog.element.contains(document.activeElement)) {
        dialog.focusInitial();
      }
    },
  };

  Drupal.AjaxCommands.prototype.openDialog = function (ajax, response, status) {
    if (!response.selector) {
      return false;
    }
    let content = document.querySelector(response.selector);
    const previous = content && Drupal.dialog.get(content);
    if (previous?.open) {
      previous.close();
      content = document.querySelector(response.selector);
    }
    if (!content) {
      content = document.createElement('div');
      content.id = response.selector.replace(/^#/, '');
    }

    const options = {
      ...response.dialogOptions,
      opener: ajax.element || document.activeElement,
      removeOnClose: true,
    };
    options.explicitButtons = Boolean(options.buttons);
    options.drupalAutoButtons =
      options.drupalAutoButtons !== false &&
      options.drupalAutoButtons !== 'false';
    const dialog = Drupal.dialog(content, options);
    ajax.wrapper ||= content.id;
    ajax.commands.insert(
      ajax,
      { ...response, command: 'insert', method: 'html' },
      status,
    );

    if (options.drupalAutoButtons && !options.explicitButtons) {
      syncButtons(content, dialog);
      const events = new AbortController();
      const { signal } = events;
      content.addEventListener(
        'dialogButtonsChange',
        () => syncButtons(content, dialog),
        { signal },
      );
      content.addEventListener('dialog:beforeclose', () => events.abort(), {
        signal,
      });
    }
    if (options.modal) {
      dialog.showModal();
    } else {
      dialog.show();
    }
  };

  Drupal.AjaxCommands.prototype.closeDialog = function (ajax, response) {
    const content = document.querySelector(response.selector);
    const dialog = content && Drupal.dialog.get(content);
    if (dialog) {
      dialog.settings.removeOnClose = !response.persist;
      dialog.close();
    }
  };

  Drupal.AjaxCommands.prototype.setDialogOption = function (ajax, response) {
    const content = document.querySelector(response.selector);
    if (content) {
      Drupal.dialog.get(content)?.update({
        [response.optionName]: response.optionValue,
      });
    }
  };

  Drupal.AjaxCommands.prototype.openModalDialogWithUrl = function (
    ajax,
    response,
  ) {
    Drupal.ajax({
      progress: { type: 'throbber' },
      dialogType: 'modal',
      dialog: response.dialogOptions || {},
      url: response.url,
      httpMethod: 'GET',
    }).execute();
  };
})(Drupal);
