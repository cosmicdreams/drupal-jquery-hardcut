/**
 * @file media_library.widget.js
 */
((Drupal, Sortable, once) => {
  /**
   * Allows users to re-order their selection with drag+drop.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches behavior to re-order selected media items.
   */
  Drupal.behaviors.MediaLibraryWidgetSortable = {
    attach(context) {
      // Allow media items to be re-sorted with drag+drop in the widget.
      once(
        'media-library-widget-sortable',
        '.js-media-library-selection',
        context,
      ).forEach((widget) => {
        Sortable.create(widget, {
          draggable: '.js-media-library-item',
          handle: '.js-media-library-item-preview',
          onEnd: () => {
            Array.from(widget.children).forEach((child, index) => {
              child.querySelector('.js-media-library-item-weight').value =
                index;
            });
          },
        });
      });
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        once
          .remove(
            'media-library-widget-sortable',
            '.js-media-library-selection',
            context,
          )
          .forEach((widget) => Sortable.get(widget)?.destroy());
      }
    },
  };

  /**
   * Allows selection order to be set without drag+drop for accessibility.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches behavior to toggle the weight field for media items.
   */
  Drupal.behaviors.MediaLibraryWidgetToggleWeight = {
    attach(context) {
      const strings = {
        show: Drupal.t('Show media item weights'),
        hide: Drupal.t('Hide media item weights'),
      };
      const mediaLibraryToggle = once(
        'media-library-toggle',
        '.js-media-library-widget-toggle-weight',
        context,
      );
      mediaLibraryToggle.forEach((toggle) => {
        toggle.addEventListener('click', (event) => {
          event.preventDefault();
          const active = toggle.classList.toggle('active');
          toggle.textContent = active ? strings.hide : strings.show;
          toggle
            .closest('.js-media-library-widget')
            .querySelectorAll('.js-media-library-item-weight')
            .forEach((weight) => {
              const wrapper = weight.parentElement;
              wrapper.style.display =
                getComputedStyle(wrapper).display === 'none' ? '' : 'none';
            });
        });
        toggle.textContent = strings.show;
      });

      once(
        'media-library-toggle',
        '.js-media-library-item-weight',
        context,
      ).forEach((weight) => {
        weight.parentElement.style.display = 'none';
      });
    },
  };

  /**
   * Disable the open button when the user is not allowed to add more items.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches behavior to disable the media library open button.
   */
  Drupal.behaviors.MediaLibraryWidgetDisableButton = {
    attach(context) {
      // When the user returns from the modal to the widget, we want to shift
      // the focus back to the open button. If the user is not allowed to add
      // more items, the button needs to be disabled. Since we can't shift the
      // focus to disabled elements, the focus is set back to the open button
      // via JavaScript by adding the 'data-disabled-focus' attribute.
      once(
        'media-library-disable',
        '.js-media-library-open-button[data-disabled-focus="true"]',
        context,
      ).forEach((button) => {
        button.focus();

        // There is a small delay between the focus set by the browser and the
        // focus of screen readers. We need to give screen readers time to shift
        // the focus as well before the button is disabled.
        setTimeout(() => {
          button.disabled = true;
        }, 50);
      });
    },
  };
})(Drupal, Sortable, once);
