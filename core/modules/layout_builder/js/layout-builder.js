/**
 * @file
 * Attaches the behaviors for the Layout Builder module.
 */

((Drupal, Sortable, once) => {
  const { ajax, behaviors, debounce, announce, formatPlural } = Drupal;

  /*
   * Tracks filtered block lists across behavior attachments within a chooser.
   */
  const filteredBlockLists = new WeakSet();

  /**
   * Provides the ability to filter the block listing in "Add block" dialog.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attach block filtering behavior to "Add block" dialog.
   */
  behaviors.layoutBuilderBlockFilter = {
    attach(context) {
      once(
        'block-filter-text',
        'input.js-layout-builder-filter',
        context,
      ).forEach((input) => {
        const dialog = input.closest('dialog') || context;
        const categories = dialog.querySelector(
          '.js-layout-builder-categories',
        );
        const categoryEntries = categories.querySelectorAll(
          '.js-layout-builder-category',
        );
        const filterLinks = categories.querySelectorAll(
          '.js-layout-builder-block-link',
        );

        function filterBlockList(event) {
          const query = event.target.value.toLowerCase();

          // Filter if the length of the query is at least 2 characters.
          if (query.length >= 2) {
            categoryEntries.forEach((category) => {
              if (!category.open) {
                category.setAttribute('remember-closed', '');
              }
              category.open = true;
              category.style.display = '';
            });

            filterLinks.forEach((link) => {
              const textMatch = link.textContent.toLowerCase().includes(query);
              link.parentElement.style.display = textMatch ? '' : 'none';
            });

            // Only display categories containing visible links.
            categoryEntries.forEach((category) => {
              const visible = [
                ...category.querySelectorAll('.js-layout-builder-block-link'),
              ].some((link) => link.getClientRects().length);
              category.style.display = visible ? '' : 'none';
            });
            announce(
              formatPlural(
                [...filterLinks].filter((link) => link.getClientRects().length)
                  .length,
                '1 block is available in the modified list.',
                '@count blocks are available in the modified list.',
              ),
            );
            filteredBlockLists.add(dialog);
          } else if (filteredBlockLists.has(dialog)) {
            filteredBlockLists.delete(dialog);
            categoryEntries.forEach((category) => {
              if (category.hasAttribute('remember-closed')) {
                category.open = false;
                category.removeAttribute('remember-closed');
              }
              category.style.display = '';
            });
            filterLinks.forEach((link) => {
              link.parentElement.style.display = '';
            });
            announce(Drupal.t('All available blocks are listed.'));
          }
        }

        input.addEventListener('input', debounce(filterBlockList, 200));
      });
    },
  };

  /**
   * Callback used in {@link Drupal.behaviors.layoutBuilderBlockDrag}.
   *
   * @param {HTMLElement} item
   *   The HTML element representing the repositioned block.
   * @param {HTMLElement} from
   *   The HTML element representing the previous parent of item
   * @param {HTMLElement} to
   *   The HTML element representing the current parent of item
   *
   * @internal This method is a callback for layoutBuilderBlockDrag and is used
   *  in FunctionalJavascript tests. It may be renamed if the test changes.
   *  @see https://www.drupal.org/node/3084730
   */
  Drupal.layoutBuilderBlockUpdate = function (item, from, to) {
    // Check if the region from the event and region for the item match.
    const itemRegion = item.closest('.js-layout-builder-region');
    if (to === itemRegion) {
      const deltaTo = item.closest('[data-layout-delta]').dataset.layoutDelta;
      const deltaFrom =
        from?.closest('[data-layout-delta]')?.dataset.layoutDelta ?? deltaTo;
      const previous = item.previousElementSibling;
      ajax({
        url: [
          item.closest('[data-layout-update-url]').dataset.layoutUpdateUrl,
          deltaFrom,
          deltaTo,
          itemRegion.dataset.region,
          item.dataset.layoutBlockUuid,
          previous?.dataset.layoutBlockUuid,
        ]
          .filter((element) => element !== undefined)
          .join('/'),
      }).execute();
    }
  };

  /**
   * Provides the ability to drag blocks to new positions in the layout.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attach block drag behavior to the Layout Builder UI.
   */
  const regionSelector = '.js-layout-builder-region';
  behaviors.layoutBuilderBlockDrag = {
    attach(context) {
      once('layout-builder-block-drag', regionSelector, context).forEach(
        (region) => {
          Sortable.create(region, {
            draggable: '.js-layout-builder-block',
            ghostClass: 'ui-state-drop',
            group: 'builder-region',
            filter: '.contextual',
            onEnd: (event) =>
              Drupal.layoutBuilderBlockUpdate(event.item, event.from, event.to),
          });
        },
      );
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        once
          .remove('layout-builder-block-drag', regionSelector, context)
          .forEach((region) => Sortable.get(region)?.destroy());
      }
    },
  };

  /**
   * Disables interactive elements in previewed blocks.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attach disabling interactive elements behavior to the Layout Builder UI.
   */
  behaviors.layoutBuilderDisableInteractiveElements = {
    attach() {
      // Disable interactive elements inside preview blocks.
      document
        .querySelectorAll('#layout-builder [data-layout-block-uuid]')
        .forEach((block) => {
          block
            .querySelectorAll('input, textarea, select')
            .forEach((element) => {
              element.disabled = true;
            });
          once(
            'layout-builder-disabled-link',
            'a:not([data-contextual-id] *)',
            block,
          ).forEach((link) => {
            ['click', 'mouseup', 'touchstart'].forEach((type) => {
              link.addEventListener(type, (event) => {
                event.preventDefault();
                event.stopPropagation();
              });
            });
          });

          // Remove interactive elements other than the contextual links from
          // the tabbing order of layout previews.
          block
            .querySelectorAll(
              'button, [href], input, select, textarea, iframe, [tabindex]:not([tabindex="-1"]):not(.tabbable)',
            )
            .forEach((element) => {
              if (!element.closest('[data-contextual-id]')) {
                element.tabIndex = -1;
              }
            });
        });
    },
  };

  const highlighted = 'is-layout-builder-highlighted';
  const movingBlocks = 'layout-builder--move-blocks-active';

  // After a dialog opens, highlight element that the dialog is acting on.
  window.addEventListener('dialog:aftercreate', (event) => {
    const layout = document.getElementById('layout-builder');
    if (layout && Drupal.offCanvas.isOffCanvas(event.target)) {
      layout.querySelectorAll(`.${highlighted}`).forEach((element) => {
        element.classList.remove(highlighted);
      });

      const id = event.target.querySelector(
        '[data-layout-builder-target-highlight-id]',
      )?.dataset.layoutBuilderTargetHighlightId;
      if (id) {
        layout
          .querySelectorAll(
            `[data-layout-builder-highlight-id="${CSS.escape(id)}"]`,
          )
          .forEach((element) => element.classList.add(highlighted));
      }

      layout.classList.remove(movingBlocks);
      const wrapperClass = event.target.querySelector(
        '[data-add-layout-builder-wrapper]',
      )?.dataset.addLayoutBuilderWrapper;
      if (wrapperClass) {
        layout.classList.add(wrapperClass);
      }
    }
  });

  /*
   * When a Layout Builder dialog is triggered, the main canvas resizes. After
   * the resize transition is complete, see if the target element is still
   * visible in viewport. If not, scroll page so the target element is again
   * visible.
   *
   * @todo Replace this custom solution when a general solution is made
   *   available with https://www.drupal.org/node/3033410
   */
  const mainCanvas = document.querySelector('[data-off-canvas-main-canvas]');
  if (mainCanvas) {
    // This event fires when canvas CSS transitions are complete.
    mainCanvas.addEventListener('transitionend', () => {
      const target = mainCanvas.querySelector(`.${highlighted}`);

      if (target) {
        const { top, bottom } = target.getBoundingClientRect();
        const viewportHeight = document.documentElement.clientHeight;

        // If the element is not in the viewport, scroll it into view.
        if (bottom < 0 || top > viewportHeight) {
          window.scrollBy({
            top: top - viewportHeight / 2,
            left: 0,
            behavior: 'smooth',
          });
        }
      }
    });
  }

  window.addEventListener('dialog:afterclose', (event) => {
    const layout = document.getElementById('layout-builder');
    if (layout && Drupal.offCanvas.isOffCanvas(event.target)) {
      layout.querySelectorAll(`.${highlighted}`).forEach((element) => {
        element.classList.remove(highlighted);
      });
      layout.classList.remove(movingBlocks);
    }
  });

  /**
   * Toggles content preview in the Layout Builder UI.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attach content preview toggle to the Layout Builder UI.
   */
  behaviors.layoutBuilderToggleContentPreview = {
    attach(context) {
      const layout = document.getElementById('layout-builder');
      const toggle = document.getElementById('layout-builder-content-preview');
      if (!layout || !toggle) {
        return;
      }
      const { contentPreviewId } = toggle.dataset;
      const blockSelector = '[data-layout-content-preview-placeholder-label]';
      const placeholderSelector =
        '.js-layout-builder-content-preview-placeholder-label';

      function disableContentPreview(canvas, scope) {
        canvas.classList.add('layout-builder--content-preview-disabled');
        const blocks = Array.from(scope.querySelectorAll(blockSelector));
        if (scope.matches?.(blockSelector)) {
          blocks.unshift(scope);
        }
        blocks.forEach((block) => {
          if (!block.querySelector(`:scope > ${placeholderSelector}`)) {
            const label = Drupal.theme(
              'layoutBuilderPrependContentPreviewPlaceholderLabel',
              block.dataset.layoutContentPreviewPlaceholderLabel,
            );
            block.insertAdjacentHTML('afterbegin', label);
          }
        });
      }

      function enableContentPreview(canvas) {
        canvas.classList.remove('layout-builder--content-preview-disabled');
        canvas
          .querySelectorAll(placeholderSelector)
          .forEach((label) => label.remove());
        canvas.querySelectorAll(`${blockSelector} > *`).forEach((child) => {
          if (child.style.display === 'none') {
            child.style.removeProperty('display');
          }
          if (getComputedStyle(child).display === 'none') {
            child.style.display = 'revert';
          }
        });
      }

      once('layout-builder-preview', toggle).forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
          const canvas = document.getElementById('layout-builder');
          if (!canvas) {
            return;
          }
          localStorage.setItem(
            contentPreviewId,
            JSON.stringify(checkbox.checked),
          );
          if (checkbox.checked) {
            enableContentPreview(canvas);
            announce(
              Drupal.t('Block previews are visible. Block labels are hidden.'),
            );
          } else {
            disableContentPreview(canvas, canvas);
            announce(
              Drupal.t('Block previews are hidden. Block labels are visible.'),
            );
          }
        });
      });

      if (JSON.parse(localStorage.getItem(contentPreviewId)) === false) {
        toggle.checked = false;
        disableContentPreview(layout, context);
      }
    },
  };

  /**
   * Creates content preview placeholder label markup.
   *
   * @param {string} contentPreviewPlaceholderText
   *   The text content of the placeholder label
   *
   * @return {string}
   *   A HTML string of the placeholder label.
   */
  Drupal.theme.layoutBuilderPrependContentPreviewPlaceholderLabel = (
    contentPreviewPlaceholderText,
  ) => {
    const contentPreviewPlaceholderLabel = document.createElement('div');
    contentPreviewPlaceholderLabel.className =
      'layout-builder-block__content-preview-placeholder-label js-layout-builder-content-preview-placeholder-label';
    contentPreviewPlaceholderLabel.textContent = contentPreviewPlaceholderText;

    return contentPreviewPlaceholderLabel.outerHTML;
  };

  // Remove all contextual links outside the layout.
  window.addEventListener(
    'drupalContextualLinkAdded',
    ({ detail: { element } }) => {
      const { contextualId } = element.dataset;
      if (contextualId && !contextualId.startsWith('layout_builder_block:')) {
        element.remove();
      }
    },
  );
})(Drupal, Sortable, once);
