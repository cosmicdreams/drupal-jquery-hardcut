/**
 * @file
 * Attaches behavior for the Editor module.
 */

(function (Drupal, drupalSettings, once) {
  const filterRequests = new WeakMap();
  const widgetListeners = new WeakMap();

  /**
   * A shared listener replaces the namespaced change and keypress handlers.
   * @param {Event} event
   *   The event that first signals a change to the textarea value.
   */
  function markChanged(event) {
    const field = event.currentTarget;
    field.setAttribute('data-editor-value-is-changed', 'true');
    field.removeEventListener('change', markChanged);
    field.removeEventListener('keypress', markChanged);
  }

  /**
   * Finds the text area field associated with the given text format selector.
   *
   * @param {HTMLElement} formatSelector
   *   A text format selector DOM element.
   *
   * @return {HTMLElement}
   *   The text area DOM element, if it was found.
   */
  function findFieldForFormatSelector(formatSelector) {
    const fieldId = formatSelector.dataset.editorFor;
    // This selector will only find text areas in the top-level document. We do
    // not support attaching editors on text areas within iframes.
    return document.getElementById(fieldId);
  }

  /**
   * Filter away XSS attack vectors when switching text formats.
   *
   * @param {HTMLElement} field
   *   The textarea DOM element.
   * @param {object} format
   *   The text format that's being activated, from
   *   drupalSettings.editor.formats.
   * @param {string} originalFormatID
   *   The text format ID of the original text format.
   * @param {function} callback
   *   A callback to be called (with no parameters) after the field's value has
   *   been XSS filtered.
   */
  function filterXssWhenSwitching(field, format, originalFormatID, callback) {
    // A text editor that already is XSS-safe needs no additional measures.
    if (format.editor.isXssSafe) {
      callback(field, format);
    }
    // Otherwise, ensure XSS safety: let the server XSS filter this value.
    else {
      const request = new AbortController();
      const requests = filterRequests.get(field) ?? new Set();
      requests.add(request);
      filterRequests.set(field, requests);
      fetch(Drupal.url(`editor/filter_xss/${format.format}`), {
        method: 'POST',
        body: new URLSearchParams({
          value: field.value,
          original_format_id: originalFormatID,
        }),
        signal: request.signal,
      })
        .then((response) => (response.ok ? response.json() : undefined))
        // As with the original success callback, ignore transport failures.
        .catch(() => undefined)
        .then((xssFilteredValue) => {
          if (
            xssFilteredValue === undefined ||
            request.signal.aborted ||
            !field.isConnected
          ) {
            return;
          }
          // If the server returns false, then no XSS filtering is needed.
          if (xssFilteredValue !== false) {
            field.value = xssFilteredValue;
          }
          callback(field, format);
        })
        .finally(() => requests.delete(request));
    }
  }

  /**
   * Changes the text editor on a text area.
   *
   * @param {HTMLElement} field
   *   The text area DOM element.
   * @param {string} newFormatID
   *   The text format we're changing to; the text editor for the currently
   *   active text format will be detached, and the text editor for the new text
   *   format will be attached.
   */
  function changeTextEditor(field, newFormatID) {
    const previousFormatID = field.getAttribute(
      'data-editor-active-text-format',
    );

    // Detach the current editor (if any) and attach a new editor.
    if (drupalSettings.editor.formats[previousFormatID]) {
      Drupal.editorDetach(
        field,
        drupalSettings.editor.formats[previousFormatID],
      );
    }
    // When no text editor is currently active, stop tracking changes.
    else {
      field.removeEventListener('change', markChanged);
      field.removeEventListener('keypress', markChanged);
    }

    // Attach the new text editor (if any).
    if (drupalSettings.editor.formats[newFormatID]) {
      const format = drupalSettings.editor.formats[newFormatID];
      filterXssWhenSwitching(
        field,
        format,
        previousFormatID,
        Drupal.editorAttach,
      );
    }

    // Store the new active format.
    field.setAttribute('data-editor-active-text-format', newFormatID);
  }

  /**
   * Handles changes in text format.
   *
   * @param {Event} event
   *   The text format change event.
   * @param {HTMLElement} field
   *   The text area DOM element.
   */
  function onTextFormatChange(event, field) {
    const select = event.currentTarget;
    const activeFormatID = field.getAttribute('data-editor-active-text-format');
    const newFormatID = select.value;

    // Prevent double-attaching if the change event is triggered manually.
    if (newFormatID === activeFormatID) {
      return;
    }

    // When changing to a text format that has a text editor associated
    // with it that supports content filtering, then first ask for
    // confirmation, because switching text formats might cause certain
    // markup to be stripped away.
    const supportContentFiltering =
      drupalSettings.editor.formats[newFormatID]
        ?.editorSupportsContentFiltering;
    // If there is no content yet, it's always safe to change the text format.
    const hasContent = field.value !== '';
    if (hasContent && supportContentFiltering) {
      const message = Drupal.t(
        'Changing the text format to %text_format will permanently remove content that is not allowed in that text format.<br><br>Save your changes before switching the text format to avoid losing data.',
        {
          '%text_format': select.selectedOptions[0].textContent,
        },
      );
      const content = document.createElement('div');
      content.innerHTML = message;
      const confirmationDialog = Drupal.dialog(content, {
        title: Drupal.t('Change text format?'),
        classes: {
          'drupal-dialog': 'editor-change-text-format-modal',
        },
        opener: select,
        buttons: [
          {
            text: Drupal.t('Continue'),
            class: 'button button--primary',
            click() {
              if (field.isConnected && select.isConnected) {
                changeTextEditor(field, newFormatID);
              }
              confirmationDialog.close();
            },
          },
          {
            text: Drupal.t('Cancel'),
            class: 'button',
            click() {
              // Restore the active format ID: cancel changing text format. We
              // cannot simply call event.preventDefault() because the
              // change event fires after the change has been accepted.
              if (select.isConnected) {
                select.value = activeFormatID;
                const eventChange = new Event('change');
                select.dispatchEvent(eventChange);
              }
              confirmationDialog.close();
            },
          },
        ],
        // Prevent this modal from being closed without the user making a choice
        // as per http://stackoverflow.com/a/5438771.
        closeOnEscape: false,
        closeButton: false,
        // Automatically destroy the DOM element that was used for the dialog.
        removeOnClose: true,
      });

      confirmationDialog.showModal();
    } else {
      changeTextEditor(field, newFormatID);
    }
  }

  /**
   * Initialize an empty object for editors to place their attachment code.
   *
   * @namespace
   */
  Drupal.editors = {};

  /**
   * Enables editors on text_format elements.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches an editor to an input element.
   * @prop {Drupal~behaviorDetach} detach
   *   Detaches an editor from an input element.
   */
  Drupal.behaviors.editor = {
    attach(context, settings) {
      // If there are no editor settings, there are no editors to enable.
      if (!settings.editor) {
        return;
      }

      once('editor', '[data-editor-for]', context).forEach((editor) => {
        const field = findFieldForFormatSelector(editor);

        // Opt-out if no supported text area was found.
        if (!field) {
          return;
        }

        // Store the current active format.
        const activeFormatID = editor.value;
        field.setAttribute('data-editor-active-text-format', activeFormatID);

        // Directly attach this text editor, if the text format is enabled.
        if (settings.editor.formats[activeFormatID]) {
          // XSS protection for the current text format/editor is performed on
          // the server side, so we don't need to do anything special here.
          Drupal.editorAttach(field, settings.editor.formats[activeFormatID]);
        }
        // When there is no text editor for this text format, still track
        // changes, because the user has the ability to switch to some text
        // editor, otherwise this code would not be executed.
        field.addEventListener('change', markChanged);
        field.addEventListener('keypress', markChanged);

        // Attach onChange handler to text format selector element.
        const change = (event) => onTextFormatChange(event, field);
        if (editor.tagName === 'SELECT') {
          editor.addEventListener('change', change);
        }
        // Detach any editor when the containing form is submitted.
        const submit = (event) => {
          // Do not detach if the event was canceled.
          if (event.defaultPrevented) {
            return;
          }
          // Detach the current editor (if any).
          if (settings.editor.formats[activeFormatID]) {
            Drupal.editorDetach(
              field,
              settings.editor.formats[activeFormatID],
              'serialize',
            );
          }
        };
        const form = field.form;
        form?.addEventListener('submit', submit);
        widgetListeners.set(editor, () => {
          editor.removeEventListener('change', change);
          form?.removeEventListener('submit', submit);
          field.removeEventListener('change', markChanged);
          field.removeEventListener('keypress', markChanged);
        });
      });
    },

    detach(context, settings, trigger) {
      const selector = '[data-editor-for]';
      let editors;
      // The 'serialize' trigger indicates that we should simply update the
      // underlying element with the new text, without destroying the editor.
      if (trigger === 'serialize') {
        // Keep the once marker when serializing without destroying the editor.
        editors = once.filter('editor', selector, context);
      } else {
        editors = once.remove('editor', [
          ...(context instanceof Element ? [context] : []),
          ...context.querySelectorAll(selector),
        ]);
      }

      editors.forEach((editor) => {
        const activeFormatID = editor.value;
        const field = findFieldForFormatSelector(editor);
        if (trigger !== 'serialize') {
          widgetListeners.get(editor)?.();
          widgetListeners.delete(editor);
        }
        if (trigger === 'unload' && field) {
          filterRequests.get(field)?.forEach((request) => request.abort());
          filterRequests.delete(field);
        }
        if (field && activeFormatID in settings.editor.formats) {
          Drupal.editorDetach(
            field,
            settings.editor.formats[activeFormatID],
            trigger,
          );
        }
      });
    },
  };

  /**
   * Attaches editor behaviors to the field.
   *
   * @param {HTMLElement} field
   *   The textarea DOM element.
   * @param {object} format
   *   The text format that's being activated, from
   *   drupalSettings.editor.formats.
   *
   * @listens event:change
   *
   * @fires event:formUpdated
   */
  Drupal.editorAttach = function (field, format) {
    if (format.editor) {
      // Attach the text editor.
      Drupal.editors[format.editor].attach(field, format);

      // Ensures form.js' 'formUpdated' event is triggered even for changes that
      // happen within the text editor.
      Drupal.editors[format.editor].onChange(field, () => {
        // Bubble the native event as the original trigger did.
        field.dispatchEvent(new Event('formUpdated', { bubbles: true }));

        // Keep track of changes, so we know what to do when switching text
        // formats and guaranteeing XSS protection.
        field.setAttribute('data-editor-value-is-changed', 'true');
      });
    }
  };

  /**
   * Detaches editor behaviors from the field.
   *
   * @param {HTMLElement} field
   *   The textarea DOM element.
   * @param {object} format
   *   The text format that's being activated, from
   *   drupalSettings.editor.formats.
   * @param {string} trigger
   *   Trigger value from the detach behavior.
   */
  Drupal.editorDetach = function (field, format, trigger) {
    if (format.editor) {
      Drupal.editors[format.editor].detach(field, format, trigger);

      // Restore the original value if the user didn't make any changes yet.
      if (field.getAttribute('data-editor-value-is-changed') === 'false') {
        field.value = field.getAttribute('data-editor-value-original');
      }
    }
  };
})(Drupal, drupalSettings, once);
