/**
 * @file
 * Provides JavaScript additions to the managed file field type.
 *
 * This file provides progress bar support (if available), popup windows for
 * file previews, and disabling of other file fields during Ajax uploads (which
 * prevents separate file fields from accidentally uploading files).
 */

(function (Drupal, once) {
  const validationSelector = 'input[data-file-extensions]';
  const fileSelector = 'input[type="file"]';

  /**
   * Attach behaviors to the file fields passed in the settings.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches validation for file extensions.
   * @prop {Drupal~behaviorDetach} detach
   *   Detaches validation for file extensions.
   */
  Drupal.behaviors.fileValidateAutoAttach = {
    attach(context) {
      once('fileValidate', validationSelector, context).forEach((input) =>
        input.addEventListener('change', Drupal.file.validateExtension),
      );
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        once
          .remove('fileValidate', [
            ...(context instanceof Element ? [context] : []),
            ...context.querySelectorAll(validationSelector),
          ])
          .forEach((input) =>
            input.removeEventListener('change', Drupal.file.validateExtension),
          );
      }
    },
  };

  /**
   * Attach behaviors to file element auto upload.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches triggers for the upload button.
   * @prop {Drupal~behaviorDetach} detach
   *   Detaches auto file upload trigger.
   */
  Drupal.behaviors.fileAutoUpload = {
    attach(context) {
      once('auto-file-upload', fileSelector, context).forEach((input) =>
        input.addEventListener('change', Drupal.file.triggerUploadButton),
      );
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        once
          .remove('auto-file-upload', [
            ...(context instanceof Element ? [context] : []),
            ...context.querySelectorAll(fileSelector),
          ])
          .forEach((input) =>
            input.removeEventListener(
              'change',
              Drupal.file.triggerUploadButton,
            ),
          );
      }
    },
  };

  /**
   * Attach behaviors to the file upload and remove buttons.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches form submit events.
   * @prop {Drupal~behaviorDetach} detach
   *   Detaches form submit events.
   */
  Drupal.behaviors.fileButtons = {
    attach(context) {
      context
        .querySelectorAll('.js-form-submit')
        .forEach((button) =>
          button.addEventListener('mousedown', Drupal.file.disableFields),
        );
      context
        .querySelectorAll('.js-form-managed-file .js-form-submit')
        .forEach((button) =>
          button.addEventListener('mousedown', Drupal.file.progressBar),
        );
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        context
          .querySelectorAll('.js-form-submit')
          .forEach((button) =>
            button.removeEventListener('mousedown', Drupal.file.disableFields),
          );
        context
          .querySelectorAll('.js-form-managed-file .js-form-submit')
          .forEach((button) =>
            button.removeEventListener('mousedown', Drupal.file.progressBar),
          );
      }
    },
  };

  /**
   * Attach behaviors to links within managed file elements for preview windows.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches triggers.
   * @prop {Drupal~behaviorDetach} detach
   *   Detaches triggers.
   */
  Drupal.behaviors.filePreviewLinks = {
    attach(context) {
      context
        .querySelectorAll('div.js-form-managed-file .file a')
        .forEach((link) =>
          link.addEventListener('click', Drupal.file.openInNewWindow),
        );
    },
    detach(context) {
      context
        .querySelectorAll('div.js-form-managed-file .file a')
        .forEach((link) =>
          link.removeEventListener('click', Drupal.file.openInNewWindow),
        );
    },
  };

  /**
   * File upload utility functions.
   *
   * @namespace
   */
  Drupal.file = Drupal.file || {
    /**
     * Client-side file input validation of file extensions.
     *
     * @name Drupal.file.validateExtension
     *
     * @param {Event} event
     *   The event triggered. For example `change`.
     */
    validateExtension(event) {
      event.preventDefault();
      const input = event.currentTarget;
      const widget = input.closest('div.js-form-managed-file');
      widget.querySelector('.file-upload-js-error')?.remove();

      const extensions = input.dataset.fileExtensions
        ?.split(/\s+/)
        .filter(Boolean);
      const validFiles = [];
      const errors = [];
      Array.from(input.files).forEach((file) => {
        if (
          extensions?.length &&
          !extensions.some((extension) =>
            file.name.toLowerCase().endsWith(`.${extension.toLowerCase()}`),
          )
        ) {
          errors.push(
            Drupal.t(
              'The selected file %filename cannot be uploaded. Only files with the following extensions are allowed: %extensions.',
              {
                '%filename': file.name,
                '%extensions': extensions.join(', '),
              },
            ),
          );
        } else {
          validFiles.push(file);
        }
      });

      if (!errors.length) {
        return;
      }

      widget.insertAdjacentHTML(
        'afterbegin',
        `<div class="messages messages--error file-upload-js-error" aria-live="polite">${errors.join('<br>')}</div>`,
      );
      if (!validFiles.length) {
        input.value = '';
        event.stopImmediatePropagation();
      } else {
        // FileList is read-only. Replace it with the files that can upload.
        const selection = new DataTransfer();
        validFiles.forEach((file) => selection.items.add(file));
        input.files = selection.files;
      }
    },

    /**
     * Trigger the upload_button mouse event to auto-upload as a managed file.
     *
     * @name Drupal.file.triggerUploadButton
     *
     * @param {Event} event
     *   The event triggered. For example `change.autoFileUpload`.
     */
    triggerUploadButton(event) {
      const input = event.currentTarget;
      const widget = input.closest('.js-form-managed-file');
      const error = widget?.querySelector('.file-upload-js-error');
      widget
        ?.querySelectorAll(
          '.js-form-submit[data-drupal-selector$="upload-button"]',
        )
        .forEach((button) => {
          const ajax =
            error &&
            Drupal.ajax?.instances.find(
              (request) => request?.element === button,
            );
          const restoreInsert = () => {
            delete ajax.commands.insert;
            document.removeEventListener('drupal:ajax-complete', onComplete);
          };
          const onComplete = (event) => {
            if (event.detail.ajax === ajax) {
              restoreInsert();
            }
          };
          const preserveSelectionError = ajax && !ajax.ajaxing;
          if (preserveSelectionError) {
            document.addEventListener('drupal:ajax-complete', onComplete);
            // Keep the rejected filenames visible when the upload replaces
            // this widget or advances to the next step of the upload form.
            const { insert } = Drupal.AjaxCommands.prototype;
            ajax.commands.insert = (request, response, status) => {
              const preserveError =
                widget.isConnected && widget.contains(error);
              const elements = insert(request, response, status);
              if (preserveError && elements?.length && !widget.isConnected) {
                const selector = `input[type="file"][name="${CSS.escape(input.name)}"]`;
                const replacement = elements
                  .map((element) => element.querySelector(selector))
                  .find(Boolean);
                (
                  replacement?.closest('.js-form-managed-file') ?? elements[0]
                ).prepend(error);
                restoreInsert();
              }
              return elements;
            };
          }
          button.dispatchEvent(
            new MouseEvent('mousedown', { bubbles: true, cancelable: true }),
          );
          if (preserveSelectionError && !ajax.ajaxing) {
            restoreInsert();
          }
        });
    },

    /**
     * Prevent file uploads when using buttons not intended to upload.
     *
     * @name Drupal.file.disableFields
     *
     * @param {Event} event
     *   The event triggered, most likely a `mousedown` event.
     */
    disableFields(event) {
      const button = event.currentTarget;
      button.dispatchEvent(new Event('formUpdated', { bubbles: true }));

      // Temporarily disable other managed file inputs during form serialization.
      const widget = button.closest('div.js-form-managed-file');
      const fields = [
        ...(button.form?.querySelectorAll(
          'div.js-form-managed-file input.js-form-file:enabled',
        ) ?? []),
      ].filter((field) => !widget?.contains(field));
      fields.forEach((field) => {
        field.disabled = true;
      });
      setTimeout(() => {
        fields.forEach((field) => {
          field.disabled = false;
        });
      }, 1000);
    },

    /**
     * Add progress bar support if possible.
     *
     * @name Drupal.file.progressBar
     *
     * @param {Event} event
     *   The event triggered, most likely a `mousedown` event.
     */
    progressBar(event) {
      const button = event.currentTarget;
      const widget = button.closest('div.js-form-managed-file');
      const progressId = widget.querySelector('input.file-progress');
      if (progressId) {
        const originalName = progressId.name;

        // Use the required identifier while serializing the upload.
        progressId.name = originalName.match(
          /APC_UPLOAD_PROGRESS|UPLOAD_IDENTIFIER/,
        )[0];
        setTimeout(() => {
          progressId.name = originalName;
        }, 1000);
      }

      // Reveal a progress bar hidden by the theme after half a second.
      setTimeout(() => {
        widget.querySelectorAll('div.ajax-progress-bar').forEach((bar) => {
          if (getComputedStyle(bar).display === 'none') {
            bar.style.display = 'block';
            const {
              height,
              paddingTop,
              paddingBottom,
              marginTop,
              marginBottom,
            } = getComputedStyle(bar);
            bar.animate(
              [
                {
                  height: '0',
                  paddingTop: '0',
                  paddingBottom: '0',
                  marginTop: '0',
                  marginBottom: '0',
                  overflow: 'hidden',
                },
                {
                  height,
                  paddingTop,
                  paddingBottom,
                  marginTop,
                  marginBottom,
                  overflow: 'hidden',
                },
              ],
              { duration: 400, easing: 'ease-in-out' },
            );
          }
        });
      }, 500);
      button.dispatchEvent(
        new Event('fileUpload', { bubbles: true, cancelable: true }),
      );
    },

    /**
     * Open links to files within forms in a new window.
     *
     * @name Drupal.file.openInNewWindow
     *
     * @param {Event} event
     *   The event triggered, most likely a `click` event.
     */
    openInNewWindow(event) {
      event.preventDefault();
      const link = event.currentTarget;
      link.target = '_blank';
      window.open(
        link.href,
        'filePreview',
        'toolbar=0,scrollbars=1,location=1,statusbar=1,menubar=0,resizable=1,width=500,height=550',
      );
    },
  };
})(Drupal, once);
