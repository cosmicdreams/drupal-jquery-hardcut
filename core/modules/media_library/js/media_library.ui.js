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
    request.isResponseCurrent = () =>
      library.isConnected && (Drupal.dialog?.hasOpenOrigin(origin) ?? true);
  }

  /**
   * Marks the tab for the media type being shown and announces the change.
   * @param {HTMLElement} tab
   *   The tab for the displayed media type.
   */
  function selectMediaType(tab) {
    const menu = tab.closest('.js-media-library-menu');
    menu.querySelector('.active-tab')?.remove();
    menu.querySelector('a.active')?.classList.remove('active');
    tab.classList.add('active');
    const { title } = tab.dataset;
    tab.innerHTML = Drupal.t(
      '<span class="visually-hidden">Show </span>@title<span class="visually-hidden"> media</span><span class="active-tab visually-hidden"> (selected)</span>',
      { '@title': title },
    );
    Drupal.announce(Drupal.t('Showing @title media.', { '@title': title }));
  }

  /**
   * Reads the selected media type from a picker link.
   * @param {HTMLAnchorElement} link
   *   A media type tab or a view display link.
   * @return {string|null}
   *   The media type machine name.
   */
  function getMediaType(link) {
    return new URL(link.href).searchParams.get('media_library_selected_type');
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
          // Update the tab and move focus after replacing the content.
          ajaxObject.afterSuccess = () => {
            if (!ajaxObject.isResponseCurrent()) {
              return;
            }
            selectMediaType(link);
            const content = library.querySelector('#media-library-content');
            if (content) {
              tabbable(content)[0]?.focus();
            }
          };
          ajaxObject.execute();
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
                library
                  .querySelector(`#media-library-view ${focusSelector}`)
                  ?.focus();
              }
              // Announce the new view is loaded to screen readers.
              if (displayAnnouncement) {
                Drupal.announce(displayAnnouncement);
              }
              // The display link may have been clicked while a type change
              // was pending. Its response shows the type from the link.
              const type = getMediaType(link);
              const tabs = library.querySelectorAll('.js-media-library-menu a');
              const tab = Array.from(tabs).find(
                (candidate) => getMediaType(candidate) === type,
              );
              if (tab && !tab.classList.contains('active')) {
                selectMediaType(tab);
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

  let selectionRemaining;

  /**
   * Updates the selected item count inside the library button pane.
   *
   * @param {number} remaining
   *   The number of remaining slots.
   */
  function updateSelectionCount(remaining) {
    const { length } = Drupal.MediaLibrary.currentSelection;
    // A negative value means an unlimited selection.
    const text =
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
    document
      .querySelectorAll('.js-media-library-selected-count')
      .forEach((count) => {
        count.textContent = text;
      });
  }

  /**
   * Updates the media library selection when loaded or media items are selected.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.MediaLibraryItemSelection = {
    attach(context, settings) {
      const forms = Array.from(
        context.querySelectorAll(
          '.js-media-library-views-form, .js-media-library-add-form',
        ),
      );
      const remaining = settings.media_library?.selection_remaining;
      if (!forms.length || remaining === undefined) {
        return;
      }
      selectionRemaining = remaining;

      const { currentSelection } = Drupal.MediaLibrary;
      const items = forms.flatMap((form) =>
        Array.from(
          form.querySelectorAll(
            '.js-media-library-item input[type="checkbox"]',
          ),
        ),
      );
      const selectionFields = forms.flatMap((form) =>
        Array.from(form.querySelectorAll('#media-library-modal-selection')),
      );

      function checkEnabled() {
        updateSelectionCount(remaining);
        if (remaining < 0 || currentSelection.length <= remaining) {
          document.getElementById('media-library-messages')?.replaceChildren();
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

      once('media-item-change', items).forEach((item) => {
        item.addEventListener('change', () => {
          const index = currentSelection.indexOf(item.value);
          if (item.checked && index === -1) {
            currentSelection.push(item.value);
          } else if (!item.checked && index !== -1) {
            currentSelection.splice(index, 1);
          }

          const value = currentSelection.join();
          const selection = document.getElementById(
            'media-library-modal-selection',
          );
          if (selection) {
            selection.value = value;
            selection.dispatchEvent(new Event('change', { bubbles: true }));
          }
          document
            .querySelectorAll('.js-media-library-add-form-current-selection')
            .forEach((input) => {
              input.value = value;
            });
        });
      });
      checkEnabled();
      once('media-library-selection-change', selectionFields).forEach(
        (input) => {
          input.addEventListener('change', checkEnabled);
        },
      );

      // Restore the selection after switching tabs or filtering the view.
      currentSelection.forEach((id) => {
        forms.forEach((form) => {
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
        if (!target.closest('.media-library-widget-modal')) {
          return;
        }
        document
          .querySelectorAll(
            '.media-library-widget-modal .drupal-dialog-buttonpane',
          )
          .forEach((pane) => {
            if (!pane.querySelector('.js-media-library-selected-count')) {
              pane.insertAdjacentHTML(
                'beforeend',
                Drupal.theme('mediaLibrarySelectionCount'),
              );
            }
          });
        updateSelectionCount(selectionRemaining);
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
