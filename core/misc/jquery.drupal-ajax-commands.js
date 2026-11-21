/**
 * @file
 * Compatibility handlers for contributed Ajax commands that use jQuery.
 *
 * @deprecated in drupal:12.0.0 and is removed from drupal:13.0.0.
 *   Use a native command or define an explicit command in your extension.
 *
 * @see https://www.drupal.org/node/CHANGE_RECORD
 */

(($, Drupal, drupalSettings) => {
  const warned = new Set();
  const nativeInputs =
    'Use a CSS selector and supported native command options';
  const migrations = {
    insert: nativeInputs,
    remove: nativeInputs,
    changed: nativeInputs,
    css: nativeInputs,
    data: "Use Drupal's element data API",
    invoke:
      'Use a native command for the operation, or define an explicit command in your extension',
  };
  const originalMethods = {
    html: $.fn.html,
    replaceWith: $.fn.replaceWith,
    append: $.fn.append,
    prepend: $.fn.prepend,
    before: $.fn.before,
    after: $.fn.after,
    remove: $.fn.remove,
  };

  function warn(contract, migration) {
    if (!warned.has(contract)) {
      warned.add(contract);
      Drupal.deprecationError({
        message: `${contract} is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. ${migration}. See https://www.drupal.org/node/CHANGE_RECORD.`,
      });
    }
  }

  const elementData = Drupal.ajax.elementData;
  const data = $.data;
  const dataMethods = [
    [$, 'data'],
    [$, 'removeData'],
    [$.fn, 'data'],
    [$.fn, 'removeData'],
  ];
  const projected = new WeakSet();

  // Copy only Drupal-owned keys, on first access and after native writes.
  // Walking the jQuery collection here avoids retaining any element solely for
  // the benefit of a library that may be attached in a later Ajax response.
  function projectData(element) {
    const entries = Array.from(elementData.entries(element));
    entries.forEach(([key, entry]) => {
      if (!projected.has(entry)) {
        if (entry.value === undefined) {
          removeData(element, key);
        } else {
          data(element, key, entry.value);
        }
        projected.add(entry);
      }
    });
    return entries;
  }

  const removeData = $.removeData;
  dataMethods.forEach(([owner, name]) => {
    const original = owner[name];
    owner[name] = function (...args) {
      const elements = owner === $ ? [args[0]] : this.toArray();
      const entries = elements.map(projectData);
      const result = original.apply(this, args);
      elements.forEach((element, index) => {
        entries[index].forEach(([key, entry]) => {
          entry.value = data(element, key);
        });
      });
      return result;
    };
  });

  const clone = $.fn.clone;
  $.fn.clone = function (withData, deepWithData = withData) {
    if (withData) {
      this.each((index, element) => {
        projectData(element);
        if (deepWithData) {
          element.querySelectorAll?.('*').forEach(projectData);
        }
      });
    }
    return clone.apply(this, arguments);
  };

  const cleanData = $.cleanData;
  $.cleanData = function (elements) {
    Array.from(elements).forEach((element) => {
      for (const [, entry] of elementData.entries(element)) {
        entry.value = undefined;
      }
    });
    return cleanData.apply(this, arguments);
  };

  Drupal.ajax.commandCompatibility = {
    overrides(method) {
      return (
        Drupal.ajax.compatibility?.hasDomMethodOverride?.(method) ??
        originalMethods[method] !== $.fn[method]
      );
    },

    getData(element, key) {
      if (
        /^(?:dialog[A-Z].*|ajaxHttpMethod|refocusBlur|disableRefocus)$/.test(
          key,
        )
      ) {
        projectData(element);
        return data(element, key);
      }
    },

    insert(ajax, response) {
      warn(
        'Using jQuery Ajax selector, method or effect inputs',
        migrations.insert,
      );
      // Get information from the response. If it is not there, default to
      // our presets.
      const $wrapper = response.selector
        ? $(response.selector)
        : $(ajax.wrapper);
      const method = response.method || ajax.method;
      const effect = ajax.getEffect(response);

      // Apply any settings from the returned JSON if available.
      const settings = response.settings || ajax.settings || drupalSettings;

      // Parse response.data into an element collection.
      const parseHTML = (htmlString) => {
        const fragment = document.createDocumentFragment();
        // Create a temporary template element.
        const template = fragment.appendChild(
          document.createElement('template'),
        );

        // Set the innerHTML of the template to the provided HTML string.
        template.innerHTML = htmlString;

        // Return the contents of the temporary template.
        return template.content.childNodes;
      };

      let $newContent = $(parseHTML(response.data));

      $newContent = $(
        Drupal.ajax.themeContent(
          'ajaxWrapperNewContent',
          $newContent,
          ajax,
          response,
        ),
      );

      // If removing content from the wrapper, detach behaviors first.
      switch (method) {
        case 'html':
        case 'replaceWith':
        case 'replaceAll':
        case 'empty':
        case 'remove':
          Drupal.detachBehaviors($wrapper.get(0), settings);
          break;
        default:
          break;
      }

      // Add the new content to the page.
      $wrapper[method]($newContent);

      // Immediately hide the new content if we're using any effects.
      if (effect.showEffect !== 'show') {
        $newContent.hide();
      }

      // Determine which effect to use and what content will receive the
      // effect, then show the new content.
      const $ajaxNewContent = $newContent.find('.ajax-new-content');
      if ($ajaxNewContent.length) {
        $ajaxNewContent.hide();
        $newContent.show();
        $ajaxNewContent[effect.showEffect](effect.showSpeed);
      } else if (effect.showEffect !== 'show') {
        $newContent[effect.showEffect](effect.showSpeed);
      }

      // Attach behaviors to all element nodes.
      $newContent.each((index, element) => {
        if (
          element.nodeType === Node.ELEMENT_NODE &&
          // Attach all JavaScript behaviors to the new content, if it was
          // successfully added to the page, this condition allows
          // `#ajax['wrapper']` to be optional.
          document.documentElement.contains(element)
        ) {
          Drupal.attachBehaviors(element, settings);
        }
      });
    },

    remove(ajax, response, status) {
      warn(
        'Using jQuery Ajax selector, method or effect inputs',
        migrations.remove,
      );
      const settings = response.settings || ajax.settings || drupalSettings;
      $(response.selector)
        .each(function () {
          Drupal.detachBehaviors(this, settings);
        })
        .remove();
    },

    changed(ajax, response, status) {
      warn(
        'Using jQuery Ajax selector, method or effect inputs',
        migrations.changed,
      );
      const $element = $(response.selector);
      if (!$element.hasClass('ajax-changed')) {
        $element.addClass('ajax-changed');
        if (response.asterisk) {
          $element
            .find(response.asterisk)
            .append(
              ` <abbr class="ajax-changed" title="${Drupal.t(
                'Changed',
              )}">*</abbr> `,
            );
        }
      }
    },

    css(ajax, response, status) {
      warn(
        'Using jQuery Ajax selector, method or effect inputs',
        migrations.css,
      );
      // eslint-disable-next-line no-jquery/no-css
      $(response.selector).css(response.argument);
    },

    data(ajax, response, status) {
      warn("DataCommand's jQuery-data semantics", migrations.data);
      const targets = $(response.selector);
      targets.data(response.name, response.value);
      if (typeof response.name === 'string' && response.value !== undefined) {
        targets.each((index, element) => {
          const key = response.name;
          elementData.set(element, key, data(element, key));
        });
      }
    },

    invoke(ajax, response, status) {
      warn('The invoke Ajax command', migrations.invoke);
      const $element = $(response.selector);
      $element[response.method](...response.args);
    },

    restripe(ajax, response, status) {
      warn('Using jQuery Ajax selector, method or effect inputs', nativeInputs);
      return $(response.selector).find('> tbody > tr, > tr').toArray();
    },
  };
})(jQuery, Drupal, drupalSettings);
