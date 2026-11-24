/**
 * @file media_library.ui.js
 */
((Drupal, once, { tabbable }) => {
  /**
   * Wrapper object for the current state of the media library.
   */
  Drupal.MediaLibrary = {
    /**
     * When a user interacts with the media library we want the selection to
     * persist as long as the media library modal is opened. We temporarily
     * store the selected items while the user filters the media library view or
     * navigates to different tabs.
     */
    currentSelection: [],
  };

  /**
   * Command to update the current media library selection.
   *
   * @param {Drupal.Ajax} [ajax]
   *   The Drupal Ajax object.
   * @param {object} response
   *   Object holding the server response.
   */
  Drupal.AjaxCommands.prototype.updateMediaLibrarySelection = function (
    ajax,
    response,
  ) {
    Object.values(response.mediaIds).forEach((value) => {
      Drupal.MediaLibrary.currentSelection.push(value);
    });
  };

  const libraryRequests = new WeakMap();

  /**
   * Keeps partial responses tied to their library and dialog session.
   *
   * @param {HTMLElement} library
   *   The media library wrapper.
   * @param {HTMLElement} link
   *   The link initiating this request.
   * @param {Drupal.Ajax} request
   *   The Ajax request to track.
   */
  function trackLibraryRequest(library, link, request) {
    const origin = Drupal.dialog?.requestOrigin(link);
    libraryRequests.set(library, request);
    request.isResponseCurrent = () =>
      library.isConnected &&
      libraryRequests.get(library) === request &&
      (Drupal.dialog?.hasOpenOrigin(origin) ?? true);
  }

  /**
   * Load media library content through AJAX.
   *
   * Standard AJAX links (using the 'use-ajax' class) replace the entire library
   * dialog. When navigating to a media type through the vertical tabs, we only
   * want to load the changed library content. This is not only more efficient,
   * but also provides a more accessible user experience for screen readers.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches behavior to vertical tabs in the media library.
   *
   * @todo Remove when the AJAX system adds support for replacing a specific
   *   selector via a link.
   *   https://www.drupal.org/project/drupal/issues/3026636
   */
  Drupal.behaviors.MediaLibraryTabs = {
    attach(context) {
      once(
        'media-library-menu-item',
        '.js-media-library-menu a',
        context,
      ).forEach((link) => {
        const menu = link.closest('.js-media-library-menu');
        link.addEventListener('keypress', (event) => {
          // Let Space activate the link, which has the button role.
          if (event.key === ' ') {
            event.preventDefault();
            event.stopPropagation();
            link.click();
          }
        });
        link.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();

          const library = menu.closest('#media-library-wrapper');
          const ajaxObject = Drupal.ajax({
            wrapper: 'media-library-content',
            url: link.href,
            dialogType: 'ajax',
            progress: {
              type: 'fullscreen',
              message: Drupal.t('Processing...'),
            },
          });

          trackLibraryRequest(library, link, ajaxObject);
          // Move focus after replacement and behavior attachment.
          ajaxObject.afterSuccess = () => {
            if (!ajaxObject.isResponseCurrent()) {
              return;
            }
            const content = library.querySelector('#media-library-content');
            if (content?.isConnected) {
              tabbable(content)[0]?.focus();
            }
          };
          ajaxObject.execute();

          // Set the selected tab.
          menu
            .querySelectorAll('.active-tab')
            .forEach((marker) => marker.remove());
          menu
            .querySelectorAll('a')
            .forEach((tab) => tab.classList.remove('active'));
          link.classList.add('active');
          const { title } = link.dataset;
          link.innerHTML = Drupal.t(
            '<span class="visually-hidden">Show </span>@title<span class="visually-hidden"> media</span><span class="active-tab visually-hidden"> (selected)</span>',
            { '@title': title },
          );

          Drupal.announce(
            Drupal.t('Showing @title media.', { '@title': title }),
          );
        });
      });
    },
  };

  /**
   * Load media library displays through AJAX.
   *
   * Standard AJAX links (using the 'use-ajax' class) replace the entire library
   * dialog. When navigating to a media library views display, we only want to
   * load the changed views display content. This is not only more efficient,
   * but also provides a more accessible user experience for screen readers.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches behavior to vertical tabs in the media library.
   *
   * @todo Remove when the AJAX system adds support for replacing a specific
   *   selector via a link.
   *   https://www.drupal.org/project/drupal/issues/3026636
   */
  Drupal.behaviors.MediaLibraryViewsDisplay = {
    attach(context) {
      const views = context.matches?.('.js-media-library-view')
        ? [context]
        : context.querySelectorAll('.js-media-library-view');
      views.forEach((view) => {
        const container = view.closest('.views-element-container');
        if (container) {
          container.id = 'media-library-view';
        }
      });

      once(
        'media-library-views-display-link',
        '.views-display-link-widget, .views-display-link-widget_table',
        context,
      ).forEach((link) =>
        link.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();

          const library = link.closest('#media-library-wrapper');
          // Add a loading and display announcement for screen reader users.
          let loadingAnnouncement = '';
          let displayAnnouncement = '';
          let focusSelector = '';
          if (link.classList.contains('views-display-link-widget')) {
            loadingAnnouncement = Drupal.t('Loading grid view.');
            displayAnnouncement = Drupal.t('Changed to grid view.');
            focusSelector = '.views-display-link-widget';
          } else if (
            link.classList.contains('views-display-link-widget_table')
          ) {
            loadingAnnouncement = Drupal.t('Loading table view.');
            displayAnnouncement = Drupal.t('Changed to table view.');
            focusSelector = '.views-display-link-widget_table';
          }

          // Replace the library view.
          const ajaxObject = Drupal.ajax({
            wrapper: 'media-library-view',
            url: link.href,
            dialogType: 'ajax',
            progress: {
              type: 'fullscreen',
              message: loadingAnnouncement || Drupal.t('Processing...'),
            },
          });

          trackLibraryRequest(library, link, ajaxObject);
          // Announce and focus the updated display after its commands finish.
          if (displayAnnouncement || focusSelector) {
            ajaxObject.afterSuccess = () => {
              if (!ajaxObject.isResponseCurrent()) {
                return;
              }
              // The AJAX link replaces the whole view, including the clicked
              // link. Move the focus back to the clicked link when the view is
              // replaced.
              if (focusSelector) {
                library.querySelector(focusSelector)?.focus();
              }
              // Announce the new view is loaded to screen readers.
              if (displayAnnouncement) {
                Drupal.announce(displayAnnouncement);
              }
            };
          }

          ajaxObject.execute();

          // Announce the new view is being loaded to screen readers.
          // @todo Replace custom announcement when
          //   https://www.drupal.org/project/drupal/issues/2973140 is in.
          if (loadingAnnouncement) {
            Drupal.announce(loadingAnnouncement);
          }
        }),
      );
    },
  };

  const remainingSlots = new WeakMap();

  /**
   * Updates the selected item count inside the library's button pane.
   *
   * @param {HTMLElement} library
   *   The wrapper of the media library.
   * @param {number} remaining
   *   The number of remaining slots.
   */
  function updateSelectionCount(library, remaining) {
    const count = library
      .closest('.media-library-widget-modal')
      ?.querySelector('.js-media-library-selected-count');
    if (!count) {
      return;
    }
    const { length } = Drupal.MediaLibrary.currentSelection;
    // A negative value means an unlimited selection.
    count.textContent =
      remaining < 0
        ? Drupal.formatPlural(
            length,
            '1 item selected',
            '@count items selected',
          )
        : Drupal.formatPlural(
            remaining,
            '@selected of @count item selected',
            '@selected of @count items selected',
            { '@selected': length },
          );
  }

  /**
   * Update the media library selection when loaded or media items are selected.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.MediaLibraryItemSelection = {
    attach(context, settings) {
      const selector =
        '.js-media-library-views-form, .js-media-library-add-form';
      const forms = context.matches?.(selector)
        ? [context]
        : context.querySelectorAll(selector);
      const currentSelection = Drupal.MediaLibrary.currentSelection;
      const remaining = settings.media_library?.selection_remaining;

      forms.forEach((form) => {
        // The upload step replaces the library wrapper with the add form.
        const library =
          form.closest('#drupal-modal') ??
          form.closest('#media-library-wrapper');
        if (!library || remaining === undefined) {
          return;
        }
        remainingSlots.set(library, remaining);
        const items = form.querySelectorAll(
          '.js-media-library-item input[type="checkbox"]',
        );
        const selection = form.querySelector('#media-library-modal-selection');

        function checkEnabled() {
          updateSelectionCount(library, remaining);
          if (remaining < 0 || currentSelection.length <= remaining) {
            library.querySelector('#media-library-messages')?.replaceChildren();
          }
          const atLimit = remaining > 0 && currentSelection.length >= remaining;
          items.forEach((item) => {
            const disabled = atLimit && !item.checked;
            item.disabled = disabled;
            item
              .closest('.js-media-library-item')
              .classList.toggle('media-library-item--disabled', disabled);
          });
        }

        // Update the selection array and both forms when an item is selected.
        once('media-item-change', items).forEach((item) => {
          item.addEventListener('change', () => {
            const index = currentSelection.indexOf(item.value);
            if (item.checked && index === -1) {
              currentSelection.push(item.value);
            } else if (!item.checked && index !== -1) {
              currentSelection.splice(index, 1);
            }

            const value = currentSelection.join();
            if (selection) {
              selection.value = value;
              selection.dispatchEvent(new Event('change', { bubbles: true }));
            }

            library
              .querySelectorAll('.js-media-library-add-form-current-selection')
              .forEach((input) => {
                input.value = value;
              });
          });
        });
        checkEnabled();
        if (selection) {
          once('media-library-selection-change', selection).forEach((input) => {
            input.addEventListener('change', checkEnabled);
          });
        }

        // Restore the selection after switching tabs or filtering the view.
        currentSelection.forEach((id) => {
          form
            .querySelectorAll(
              `input[type="checkbox"][value="${CSS.escape(id)}"]`,
            )
            .forEach((item) => {
              item.checked = true;
              item.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
      });

      if (!once('media-library-selection-info', 'html').length) {
        return;
      }
      window.addEventListener('dialog:aftercreate', ({ target }) => {
        const pane = target
          .closest('.media-library-widget-modal')
          ?.querySelector('.drupal-dialog-buttonpane');
        if (pane && target.querySelector('#media-library-wrapper')) {
          pane.insertAdjacentHTML(
            'beforeend',
            Drupal.theme('mediaLibrarySelectionCount'),
          );
          updateSelectionCount(target, remainingSlots.get(target) ?? remaining);
        }
      });
    },
  };

  /**
   * Clear the current selection.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches behavior to clear the selection when the library modal closes.
   */
  Drupal.behaviors.MediaLibraryModalClearSelection = {
    attach() {
      if (!once('media-library-clear-selection', 'html').length) {
        return;
      }
      window.addEventListener('dialog:afterclose', ({ target }) => {
        if (target.closest('.media-library-widget-modal')) {
          // Keep the array reference used by existing listeners.
          Drupal.MediaLibrary.currentSelection.length = 0;
        }
      });
    },
  };

  /**
   * Theme function for the selection count.
   *
   * @return {string}
   *   The corresponding HTML.
   */
  Drupal.theme.mediaLibrarySelectionCount = function () {
    return `<div class="media-library-selected-count js-media-library-selected-count" role="status" aria-live="polite" aria-atomic="true"></div>`;
  };
})(Drupal, once, window.tabbable);
