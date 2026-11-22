/**
 * @file
 * Machine name functionality.
 *
 * @internal
 */

(function (Drupal, drupalSettings, transliterateLibrary) {
  const suffixDisplay = new WeakMap();

  /**
   * Trims string by a character.
   *
   * @param {string} string
   *   The string to trim.
   * @param {string} character
   *   The characters to trim.
   *
   * @returns {string}
   *   A trimmed string.
   */
  const trimByChar = (string, character) => {
    const first = [...string].findIndex((char) => char !== character);
    const last = [...string].reverse().findIndex((char) => char !== character);
    return string.substring(first, string.length - last);
  };

  /**
   * Transform a human-readable name to a machine name.
   *
   * @param {string} source
   *   A string to transform.
   * @see trans {object} settings
   *   The machine name settings for the corresponding field.
   * @param {string} settings.replace_pattern
   *   A regular expression (without modifiers) matching disallowed characters
   *   in the machine name; e.g., '[^a-z0-9]+'.
   * @param {string} settings.replace
   *   A character to replace disallowed characters with; e.g., '_' or '-'.
   * @param {number} settings.maxlength
   *   The maximum length of the machine name.
   *
   * @return {string}
   *   The machine name string.
   */
  const prepareMachineName = (source, settings) => {
    const rx = new RegExp(settings.replace_pattern, 'g');

    return trimByChar(
      source
        .toLowerCase()
        .replace(rx, settings.replace)
        .substring(0, settings.maxlength),
      settings.replace,
    );
  };

  /**
   * Attach the machine-readable name form element behavior.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches machine-name behaviors.
   */
  Drupal.behaviors.machineName = {
    /**
     * Attaches the behavior.
     *
     * @param {Element} context
     *   The context for attaching the behavior.
     * @param {object} settings
     *   Settings object.
     * @param {object} settings.machineName
     *   A list of elements to process, keyed by the HTML ID of the form
     *   element containing the human-readable value. Each element is an object
     *   defining the following properties:
     *   - target: The HTML ID of the machine name form element.
     *   - suffix: The HTML ID of a container to show the machine name preview
     *     in (usually a field suffix after the human-readable name
     *     form element).
     *   - label: The label to show for the machine name preview.
     *   - replace_pattern: A regular expression (without modifiers) matching
     *     disallowed characters in the machine name; e.g., '[^a-z0-9]+'.
     *   - replace: A character to replace disallowed characters with; e.g.,
     *     '_' or '-'.
     *   - standalone: Whether the preview should stay in its own element
     *     rather than the suffix of the source element.
     *   - field_prefix: The #field_prefix of the form element.
     *   - field_suffix: The #field_suffix of the form element.
     */
    attach(context, settings) {
      Object.entries(settings.machineName || {}).forEach(
        ([sourceId, options]) => {
          const sources = context.querySelectorAll(sourceId);
          sources.forEach((source) =>
            source.classList.add('machine-name-source'),
          );
          const [source] = once('machine-name', sources);
          const target = context.querySelector(options.target);
          const suffix = context.querySelector(options.suffix);
          const wrapper = target?.closest('.js-form-item');
          target?.classList.add('machine-name-target');

          if (!source || !target || !suffix || !wrapper) {
            return;
          }
          // Leave invalid existing machine names available for correction.
          if (target.classList.contains('error') && target.value.trim()) {
            return;
          }
          options.maxlength = target.getAttribute('maxlength') ?? undefined;
          wrapper.classList.add('hidden');
          target.removeAttribute('required');

          const machine = target.value;
          const preview = document.createElement('span');
          preview.className = 'machine-name-value';
          preview.innerHTML =
            options.field_prefix +
            Drupal.checkPlain(machine) +
            options.field_suffix;
          suffix.replaceChildren();
          if (options.label) {
            suffix.insertAdjacentHTML(
              'beforeend',
              `<span class="machine-name-label">${options.label}: </span>`,
            );
          }
          suffix.append(preview);
          suffixDisplay.set(suffix, suffix.style.display);

          if (target.disabled) {
            return;
          }
          const data = { source, target, suffix, wrapper, preview, options };
          const requiresTransliteration = (value) =>
            !/^[A-Za-z0-9_\s]*$/.test(value);
          const update = (event) => {
            const value = event.target.value;
            const name = requiresTransliteration(value)
              ? this.transliterate(value, options)
              : prepareMachineName(value, options);
            this.showMachineNameNative(name, data);
          };

          // Initialize the preview from the human-readable value if appropriate.
          if (machine === '' && source.value !== '') {
            const initial = requiresTransliteration(source.value)
              ? machine
              : prepareMachineName(source.value, options);
            this.showMachineNameNative(initial, data);
          }

          const link = document.createElement('span');
          link.className = 'admin-link';
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'link';
          button.setAttribute('aria-label', Drupal.t('Edit machine name'));
          button.dataset.drupalSelector = `${target.dataset.drupalSelector}-machine-name-admin-link`;
          button.textContent = Drupal.t('Edit');
          link.append(button);
          suffix.append(link);

          const stopUpdates = [];
          Drupal.form.listen(link, 'click', undefined, () => {
            wrapper.classList.remove('hidden');
            if (target.dataset.machineNameRequireWhenVisible) {
              target.required = true;
              delete target.dataset.machineNameRequireWhenVisible;
            }
            target.focus();
            suffix.style.display = 'none';
            stopUpdates.forEach((stop) => stop());
          });
          Drupal.form.listen(link, 'keyup', undefined, (event) => {
            if (event.key === 'Enter') {
              event.preventDefault();
              event.stopImmediatePropagation();
              event.target.click();
            }
          });
          Drupal.form.listen(link, 'keydown', undefined, (event) => {
            if (event.key === 'Enter') {
              event.preventDefault();
            }
          });

          // An existing machine name is preserved until the input is edited.
          if (target.value === '') {
            ['change', 'input'].forEach((type) => {
              stopUpdates.push(
                Drupal.form.listen(source, type, 'machineName', update),
              );
            });
            update({ target: source });
          }
        },
      );
    },

    /**
     * Updates the preview and target using native elements.
     *
     * Assign a replacement to this method to register a native override.
     *
     * @param {string} machine
     *   The machine name to display.
     * @param {object} data
     *   The source, target, suffix, wrapper and preview elements, and options.
     */
    showMachineNameNative(machine, data) {
      const { target, suffix, preview, options } = data;
      if (machine !== '') {
        if (machine !== options.replace) {
          target.value = machine;
          preview.innerHTML =
            options.field_prefix +
            Drupal.checkPlain(machine) +
            options.field_suffix;
        }
        suffix.style.display = suffixDisplay.get(suffix) || '';
        if (getComputedStyle(suffix).display === 'none') {
          suffix.style.display = 'inline';
        }
      } else {
        suffix.style.display = 'none';
        target.value = machine;
        preview.replaceChildren();
      }
    },

    /**
     * Transliterate a human-readable name to a machine name.
     *
     * @param {string} source
     *   A string to transliterate.
     * @param {object} settings
     *   The machine name settings for the corresponding field.
     * @param {string} settings.replace_pattern
     *   A regular expression (without modifiers) matching disallowed characters
     *   in the machine name; e.g., '[^a-z0-9]+'.
     * @param {string} settings.replace
     *   A character to replace disallowed characters with; e.g., '_' or '-'.
     * @param {number} settings.maxlength
     *   The maximum length of the machine name.
     *
     * @return {string}
     *   The transliterated source string.
     */
    transliterate(source, settings) {
      const languageOverrides =
        drupalSettings.transliteration_language_overrides[
          drupalSettings.langcode
        ];
      const replace = {};
      if (languageOverrides) {
        Object.keys(languageOverrides).forEach((key) => {
          // Updates the keys from hexadecimal to strings.
          replace[String.fromCharCode(key)] = languageOverrides[key];
        });
      }

      const transliteratedSource = transliterateLibrary(source, { replace });

      return prepareMachineName(transliteratedSource, settings);
    },
  };
  document.dispatchEvent(new Event('drupal:machine-name-ready'));
})(Drupal, drupalSettings, transliteration.transliterate);
