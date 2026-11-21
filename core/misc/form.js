/**
 * @file
 * Form features.
 */

/**
 * Triggers when a value in the form changed.
 *
 * The event triggers when content is typed or pasted in a text field, before
 * the change event triggers.
 *
 * @event formUpdated
 */

/**
 * Triggers when a click on a page fragment link or hash change is detected.
 *
 * If a link click also changes the hash, this event is dispatched once.
 * The event bubbles from the body and its detail is the target element or null.
 *
 * @event formFragmentLinkClickOrHashChange
 */

((Drupal, debounce) => {
  const summaries = new WeakMap();
  const formListeners = new WeakMap();

  /**
   * Binds a form interaction with an optional delegation selector.
   *
   * @internal
   */
  function listen(target, type, namespace, listener, selector) {
    const binding = {
      target,
      type,
      namespace,
      selector,
      listener,
      formInteraction: true,
      stop() {
        target.removeEventListener(type, handle);
        unregister();
      },
    };
    const handle = (event) => {
      if (binding.handlesNativeEvent) {
        return;
      }
      if (!selector) {
        listener(event, target);
        return;
      }
      if (type === 'click' && event.button >= 1) {
        return;
      }
      const closest = event.target.closest?.(selector);
      if (closest && closest !== target && target.contains(closest)) {
        listener(event, closest);
      }
    };
    target.addEventListener(type, handle);
    const unregister = Drupal.eventBindings.register(binding);
    return binding.stop;
  }

  /**
   * Sends one bubbling form event from the original target.
   *
   * @internal
   */
  function publish(target, type, detail) {
    Drupal.eventBindings.publish(
      target,
      new CustomEvent(type, { detail, bubbles: true, cancelable: true }),
    );
  }

  /**
   * Form summary operations.
   *
   * @namespace
   */
  Drupal.form = {
    /**
     * Retrieves the trimmed summary for an element.
     *
     * @param {HTMLElement} element
     *   The element whose summary is requested.
     * @return {string}
     *   The summary, or an empty string when none has been set.
     */
    getSummary(element) {
      const callback = summaries.get(element)?.callback;
      const result = callback?.(element);
      return result ? result.trim() : '';
    },

    /**
     * Sets a summary for an element or group of elements.
     *
     * Each formUpdated event on any element in the group causes a
     * summaryUpdated event on every element in the group.
     *
     * @param {HTMLElement|Iterable<HTMLElement>} elements
     *   The element or list of elements receiving the summary.
     * @param {string|function(HTMLElement): string} summary
     *   The text or a callback evaluated with the summarized element.
     * @return {HTMLElement|Iterable<HTMLElement>}
     *   The original element or list.
     */
    setSummary(elements, summary) {
      const group =
        elements?.nodeType === Node.ELEMENT_NODE
          ? [elements]
          : Array.from(elements || []);
      const callback = typeof summary === 'function' ? summary : () => summary;
      const update = () => {
        group.forEach((element) => publish(element, 'summaryUpdated'));
      };

      group.forEach((element) => {
        summaries.get(element)?.stop();
        const stop = listen(element, 'formUpdated', 'summary', update);
        summaries.set(element, { callback, stop });
      });
      update();
      return elements;
    },

    listen,
    publish,
  };

  /**
   * Prevents consecutive form submissions of identical form values.
   *
   * The comparison uses the form's submitted values. It runs for submission
   * events, excludes GET forms, and does not compare the bytes of file inputs.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.formSingleSubmit = {
    attach() {
      once('form-single-submit', 'body').forEach((body) => {
        listen(
          body,
          'submit',
          'singleSubmit',
          (event, form) => {
            const values = new URLSearchParams(new FormData(form)).toString();
            if (form.dataset.drupalFormSubmitLast === values) {
              event.preventDefault();
            } else {
              form.dataset.drupalFormSubmitLast = values;
            }
          },
          'form:not([method~="GET"])',
        );
      });
    },
  };

  /**
   * Collects the IDs of named form fields.
   *
   * @param {HTMLFormElement} form
   *   The form element to search.
   * @return {string}
   *   The form field IDs in their current order.
   */
  function fieldsList(form) {
    return Array.from(
      form.querySelectorAll('[name][id]'),
      (element) => element.id,
    ).join(',');
  }

  /**
   * Finds forms inside a behavior context, including the context itself.
   *
   * @param {Document|HTMLElement} context
   *   The behavior context.
   * @return {HTMLFormElement[]|NodeList}
   *   The forms to initialize or unload.
   */
  function formsIn(context) {
    return context.tagName === 'FORM'
      ? [context]
      : context.querySelectorAll('form');
  }

  /**
   * Triggers formUpdated when a form is modified.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.formUpdated = {
    attach(context) {
      once('form-updated', formsIn(context)).forEach((form) => {
        const update = debounce((event) => {
          publish(event.target, 'formUpdated');
        }, 300);
        form.dataset.drupalFormFields = fieldsList(form);
        formListeners.set(
          form,
          ['change', 'input'].map((type) =>
            listen(form, type, 'formUpdated', update),
          ),
        );
      });
      // Ajax can reattach behaviors to a form whose fields have changed.
      if (
        context.tagName === 'FORM' &&
        fieldsList(context) !== context.dataset.drupalFormFields
      ) {
        publish(context, 'formUpdated');
      }
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        once.remove('form-updated', formsIn(context)).forEach((form) => {
          delete form.dataset.drupalFormFields;
          formListeners.get(form)?.forEach((stop) => stop());
          formListeners.delete(form);
        });
      }
    },
  };

  /**
   * Prepopulates form fields with information from the visitor browser.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.fillUserInfoFromBrowser = {
    attach() {
      const userInfo = ['name', 'mail', 'homepage'];
      const forms = once(
        'user-info-from-browser',
        '[data-user-info-from-browser]',
      );
      if (!forms.length) {
        return;
      }
      const findFields = (name) =>
        forms.flatMap((form) =>
          Array.from(form.querySelectorAll(`[name=${name}]`)),
        );
      userInfo.forEach((name) => {
        const fields = findFields(name);
        const saved = localStorage.getItem(`Drupal.visitor.${name}`);
        const first = fields[0];
        if (
          saved &&
          first &&
          (!first.value || first.dataset.drupalDefaultValue === first.value)
        ) {
          fields.forEach((element) => {
            element.value = saved;
          });
        }
      });
      forms.forEach((form) =>
        listen(form, 'submit', undefined, () => {
          userInfo.forEach((name) => {
            const field = findFields(name)[0];
            if (field) {
              localStorage.setItem(`Drupal.visitor.${name}`, field.value);
            }
          });
        }),
      );
    },
  };

  const fragmentChanged = debounce(
    (event, source) => {
      const hash = (
        event.type === 'click' ? source.hash : window.location.hash
      ).substring(1);
      if (hash) {
        const target = document.getElementById(hash);
        publish(document.body, 'formFragmentLinkClickOrHashChange', target);
        // Allow the browser and any listeners to reveal the target first.
        setTimeout(() => target?.focus(), 300);
      }
    },
    300,
    true,
  );
  listen(window, 'hashchange', 'form-fragment', fragmentChanged);
  listen(document, 'click', 'form-fragment', fragmentChanged, 'a[href*="#"]');

  document.dispatchEvent(new Event('drupal:form-ready'));
})(Drupal, Drupal.debounce);
