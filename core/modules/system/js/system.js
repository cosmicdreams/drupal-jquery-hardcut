/**
 * @file
 * Copies field values into empty dependent inputs.
 */

((Drupal, drupalSettings) => {
  const ids = new Set();
  const listeners = new WeakMap();
  const copyEvent = 'value:copy';

  /**
   * Converts configured field IDs to an ordered query selector.
   */
  function selector(fieldIds) {
    return Array.from(fieldIds, (id) => `#${CSS.escape(id)}`).join(', ');
  }

  Drupal.behaviors.copyFieldValue = {
    attach() {
      Object.keys(drupalSettings.copyFieldValue || {}).forEach((id) =>
        ids.add(id),
      );
      if (!ids.size) {
        return;
      }

      const add = (target, type, methodName) => {
        const stop = Drupal.formEvents.listen(target, type, (event) =>
          this[methodName].call(target, event),
        );
        listeners.set(target, stop);
      };
      once('copy-field-values', 'body').forEach((body) => {
        add(body, copyEvent, 'valueTargetCopyHandlerNative');
      });
      once('copy-field-values', selector(ids)).forEach((source) => {
        add(source, 'blur', 'valueSourceBlurHandlerNative');
      });
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload' && ids.size) {
        once
          .remove('copy-field-values', `body, ${selector(ids)}`)
          .forEach((element) => {
            listeners.get(element)?.();
            listeners.delete(element);
          });
      }
    },

    /**
     * Fills an empty target from the native event's detail.
     */
    valueTargetCopyHandlerNative({ target, detail }) {
      if (target.value === '') {
        target.value = detail;
      }
    },

    /**
     * Publishes a native value:copy event for each target of a blurred source.
     */
    valueSourceBlurHandlerNative({ target }) {
      const targetIds = drupalSettings.copyFieldValue[target.id];
      if (targetIds?.length) {
        document.querySelectorAll(selector(targetIds)).forEach((element) => {
          Drupal.formEvents.publish(element, copyEvent, target.value);
        });
      }
    },
  };
})(Drupal, drupalSettings);
