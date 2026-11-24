/**
 * @file
 * Attaches behavior for the Editor module.
 */

((Drupal, once) => {
  const widgets = new WeakMap();
  const selector = '[data-editor-for]';

  function findField(select) {
    const field = `#${CSS.escape(select.dataset.editorFor)}`;
    const scope = select.closest('.js-text-format-wrapper') || select.form;
    return scope?.querySelector(field);
  }

  function activeFormat({ field, formats }) {
    return formats[field.dataset.editorActiveTextFormat];
  }

  function serialize(widget) {
    const format = activeFormat(widget);
    if (format) {
      Drupal.editorDetach(widget.field, format, 'serialize');
    }
    // The value and its format must describe the same editor while a switch
    // is waiting for the filter or for the previous editor to be destroyed.
    widget.select.value = widget.field.dataset.editorActiveTextFormat;
  }

  async function changeTextEditor(widget, newFormatID) {
    const { field, select, formats, listeners } = widget;
    const previousFormatID = field.dataset.editorActiveTextFormat;
    const previousFormat = activeFormat(widget);
    const format = formats[newFormatID];
    const request = new AbortController();
    widget.request?.abort();
    widget.request = request;
    const isCurrent = () =>
      !request.signal.aborted &&
      !listeners.signal.aborted &&
      field.isConnected &&
      select.isConnected;
    try {
      let filtered = false;
      if (newFormatID !== previousFormatID && format && !format.isXssSafe) {
        let value;
        // An edit made while the request is in flight must also be filtered.
        do {
          Drupal.editorDetach(field, previousFormat, 'serialize');
          value = field.value;
          // eslint-disable-next-line no-await-in-loop
          const response = await fetch(
            Drupal.url(`editor/filter_xss/${format.format}`),
            {
              method: 'POST',
              body: new URLSearchParams({
                value,
                original_format_id: previousFormatID,
              }),
              signal: request.signal,
            },
          );
          if (!isCurrent()) {
            return;
          }
          if (!response.ok) {
            throw new Error(response.statusText);
          }
          // eslint-disable-next-line no-await-in-loop
          filtered = await response.json();
          if (!isCurrent()) {
            return;
          }
          Drupal.editorDetach(field, previousFormat, 'serialize');
        } while (field.value !== value);
      }

      if (!widget.destroying) {
        widget.destroying = Drupal.editorDetach(field, previousFormat);
      }
      await widget.destroying;
      if (!isCurrent()) {
        return;
      }
      widget.destroying = null;
      if (filtered !== false) {
        field.value = filtered;
        // Never restore the unfiltered text over a successful round-trip.
        field.dataset.editorValueOriginal = filtered;
      }
      field.dataset.editorActiveTextFormat = newFormatID;
      select.value = newFormatID;
      Drupal.editorAttach(field, format);
    } catch (error) {
      if (isCurrent()) {
        select.value = previousFormatID;
        if (widget.destroying) {
          changeTextEditor(widget, previousFormatID);
        }
        Drupal.announce(
          Drupal.t('The text format could not be changed.'),
          'assertive',
        );
        console.error(error);
      }
    } finally {
      if (widget.request === request) {
        widget.request = null;
      }
    }
  }

  function onTextFormatChange(widget) {
    const { field, select, formats } = widget;
    const activeFormatID = field.dataset.editorActiveTextFormat;
    const newFormatID = select.value;
    widget.request?.abort();
    widget.confirmation?.close();

    if (newFormatID === activeFormatID) {
      if (widget.destroying) {
        changeTextEditor(widget, newFormatID);
      }
      return;
    }

    const format = formats[newFormatID];
    // Inspect the current editor content rather than a stale textarea value.
    Drupal.editorDetach(field, activeFormat(widget), 'serialize');
    if (!field.value || !format?.editorSupportsContentFiltering) {
      changeTextEditor(widget, newFormatID);
      return;
    }

    const content = document.createElement('div');
    content.innerHTML = Drupal.t(
      'Changing the text format to %text_format will permanently remove content that is not allowed in that text format.<br><br>Save your changes before switching the text format to avoid losing data.',
      { '%text_format': select.selectedOptions[0].textContent },
    );
    const confirmation = Drupal.dialog(content, {
      title: Drupal.t('Change text format?'),
      classes: { 'drupal-dialog': 'editor-change-text-format-modal' },
      opener: select,
      buttons: [
        {
          text: Drupal.t('Continue'),
          class: 'button button--primary',
          click() {
            confirmation.close();
            changeTextEditor(widget, newFormatID);
          },
        },
        {
          text: Drupal.t('Cancel'),
          class: 'button',
          click() {
            select.value = activeFormatID;
            select.dispatchEvent(new Event('change'));
          },
        },
      ],
      closeOnEscape: false,
      closeButton: false,
      removeOnClose: true,
    });
    content.addEventListener(
      'dialog:afterclose',
      () => {
        widget.confirmation = null;
      },
      { once: true },
    );
    widget.confirmation = confirmation;
    confirmation.showModal();
  }

  /**
   * Editor implementations keyed by plugin ID.
   *
   * @namespace
   */
  Drupal.editors = {};

  /**
   * Enables editors on text_format elements.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.editor = {
    attach(context, settings) {
      if (!settings.editor) {
        return;
      }
      const candidates = context.matches?.(selector)
        ? context
        : context.querySelectorAll(selector);
      once('editor', candidates).forEach((select) => {
        const field = findField(select);
        if (!field) {
          once.remove('editor', select);
          return;
        }

        const listeners = new AbortController();
        const { signal } = listeners;
        const widget = {
          field,
          select,
          formats: settings.editor.formats,
          listeners,
        };
        widgets.set(select, widget);
        field.dataset.editorActiveTextFormat = select.value;
        Drupal.editorAttach(field, settings.editor.formats[select.value]);

        // Track changes in the textarea when it is used without an editor.
        const markChanged = () => {
          field.dataset.editorValueIsChanged = 'true';
        };
        field.addEventListener('input', markChanged, { signal });
        field.addEventListener('change', markChanged, { signal });

        if (select.tagName === 'SELECT') {
          select.addEventListener('change', () => onTextFormatChange(widget), {
            signal,
          });
        }
        field.form?.addEventListener(
          'submit',
          (event) => {
            if (!event.defaultPrevented) {
              serialize(widget);
            }
          },
          { signal },
        );
      });
    },

    detach(context, settings, trigger) {
      if (trigger === 'move') {
        return;
      }
      const candidates = context.matches?.(selector)
        ? context
        : context.querySelectorAll(selector);
      once.filter('editor', candidates).forEach((select) => {
        const widget = widgets.get(select);
        if (trigger === 'serialize') {
          serialize(widget);
          return;
        }

        widget.listeners.abort();
        widget.request?.abort();
        widget.confirmation?.close();
        if (!widget.destroying) {
          Drupal.editorDetach(widget.field, activeFormat(widget), trigger);
        }
        widgets.delete(select);
        once.remove('editor', select);
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
    if (format?.editor) {
      // Attach the text editor.
      Drupal.editors[format.editor].attach(field, format);

      // Ensures form.js' 'formUpdated' event is triggered even for changes that
      // happen within the text editor.
      Drupal.editors[format.editor].onChange(field, () => {
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
    if (format?.editor) {
      const result = Drupal.editors[format.editor].detach(
        field,
        format,
        trigger,
      );

      // Restore the original value if the user didn't make any changes yet.
      if (field.getAttribute('data-editor-value-is-changed') === 'false') {
        field.value = field.getAttribute('data-editor-value-original');
      }
      return result;
    }
  };
})(Drupal, once);
