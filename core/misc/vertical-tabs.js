/**
 * @file
 * Renders groups of details elements as vertical tabs.
 */

((Drupal, drupalSettings) => {
  const tabs = new WeakMap();
  const displayStyles = new WeakMap();

  /**
   * Hides an element, preserving its inline display value.
   */
  function hide(element) {
    if (!displayStyles.has(element) && element.style.display !== 'none') {
      displayStyles.set(element, element.style.display);
    }
    element.style.display = 'none';
  }

  /**
   * Restores a hidden element's display value.
   */
  function show(element) {
    if (element.style.display === 'none') {
      element.style.display = displayStyles.get(element) ?? '';
    }
    displayStyles.delete(element);
  }

  /**
   * Returns the other panes in this tab group in document order.
   */
  function siblingPanes(pane) {
    return Array.from(pane.parentElement?.children || []).filter(
      (element) => element !== pane && tabs.has(element),
    );
  }

  /**
   * Marks the group's first visible tab.
   */
  function markFirstTab(item) {
    const items = Array.from(item.parentElement?.children || []);
    items.forEach((element) => element.classList.remove('first'));
    items
      .find((element) => element.getClientRects().length)
      ?.classList.add('first');
  }

  /**
   * The native interface for locating tabs and theming their markup.
   */
  Drupal.verticalTabs = {
    /**
     * Finds a tab by its details pane.
     *
     * @param {HTMLDetailsElement} pane
     *   The details pane.
     * @return {Drupal.verticalTab|undefined}
     *   The tab, if the pane was converted.
     */
    get(pane) {
      return tabs.get(pane);
    },

    /**
     * Creates native markup. Set Drupal.theme.verticalTabElements to theme it.
     *
     * @internal
     */
    createMarkup(settings) {
      return Drupal.theme('verticalTabElements', settings);
    },
  };

  /**
   * Transforms details elements into tab panes on wide viewports.
   */
  Drupal.behaviors.verticalTabs = {
    attach(context) {
      const width = drupalSettings.widthBreakpoint || 640;
      if (window.matchMedia(`(max-width: ${width}px)`).matches) {
        return;
      }

      once('vertical-tabs-fragments', 'body').forEach((body) => {
        Drupal.form.listen(
          body,
          'formFragmentLinkClickOrHashChange',
          'verticalTabs',
          ({ detail }) => {
            for (
              let pane = detail?.parentElement;
              pane;
              pane = pane.parentElement
            ) {
              tabs.get(pane)?.focus();
            }
          },
        );
      });

      once('vertical-tabs', '[data-vertical-tabs-panes]', context).forEach(
        (panes) => {
          panes.classList.add('vertical-tabs__panes');
          const selectedId = panes.querySelector(
            '.vertical-tabs__active-tab',
          )?.value;
          const details = panes.querySelectorAll(':scope > details');
          if (!details.length) {
            return;
          }

          const wrapper = document.createElement('div');
          wrapper.className = 'vertical-tabs clearfix';
          const menu = document.createElement('ul');
          menu.className = 'vertical-tabs__menu';
          panes.before(wrapper);
          wrapper.append(menu, panes);

          let selected;
          details.forEach((pane) => {
            // Only copy the title, excluding the optional dynamic summary.
            const title =
              pane.querySelector(':scope > summary')?.firstChild?.textContent ||
              '';
            const tab = new VerticalTab({ title, detailsElement: pane });
            menu.append(tab.itemElement);
            pane.classList.remove('collapsed');
            pane.removeAttribute('open');
            pane.classList.add('vertical-tabs__pane');
            tabs.set(pane, tab);
            pane.dispatchEvent(
              new CustomEvent('drupal:vertical-tab-created', {
                bubbles: true,
                detail: tab,
              }),
            );
            if (pane.id === selectedId) {
              selected = pane;
            }
          });
          menu.firstElementChild.classList.add('first');
          menu.lastElementChild.classList.add('last');

          if (!selected) {
            const fragment = document.getElementById(
              window.location.hash.slice(1),
            );
            selected =
              fragment &&
              panes.contains(fragment) &&
              fragment.closest('.vertical-tabs__pane');
          }
          tabs.get(selected || details[0])?.focus();
        },
      );

      context.querySelectorAll('details .form-item .error').forEach((item) => {
        const pane = item.closest('details');
        if (pane.style.display === 'none') {
          tabs.get(pane)?.linkElement.click();
        }
      });
    },
  };

  /**
   * A vertical tab and its associated native elements.
   *
   * @param {object} settings
   *   The tab settings.
   * @param {string} settings.title
   *   The tab label.
   * @param {HTMLDetailsElement} settings.detailsElement
   *   The tab's details pane.
   */
  function VerticalTab(settings) {
    const { title, ...members } = settings;
    Object.assign(this, members, Drupal.verticalTabs.createMarkup(settings));
    const { detailsElement, linkElement } = this;
    linkElement.setAttribute('href', `#${detailsElement.id}`);
    Drupal.form.listen(linkElement, 'click', undefined, (event) => {
      event.preventDefault();
      this.focus();
    });
    Drupal.form.listen(linkElement, 'keydown', undefined, (event) => {
      if (event.key === 'Enter' || event.keyCode === 13) {
        event.preventDefault();
        this.focus();
        const fields = document.querySelectorAll(
          '.vertical-tabs__pane :is(input, select, textarea, button):enabled',
        );
        Array.from(fields)
          .find((field) => field.getClientRects().length)
          ?.focus();
      }
    });
    Drupal.form.listen(detailsElement, 'summaryUpdated', undefined, () =>
      this.updateSummary(),
    );
    Drupal.form.publish(detailsElement, 'summaryUpdated');
  }

  VerticalTab.prototype = {
    /**
     * Displays this pane and marks its tab as selected.
     */
    focus() {
      const { detailsElement, itemElement, linkElement } = this;
      siblingPanes(detailsElement).forEach((pane) => {
        hide(pane);
        pane.removeAttribute('open');
        tabs.get(pane).itemElement.classList.remove('is-selected');
      });
      show(detailsElement);
      detailsElement.parentElement
        ?.querySelectorAll(':scope > .vertical-tabs__active-tab')
        .forEach((input) => {
          if (!input.getClientRects().length) {
            input.value = detailsElement.id;
          }
        });
      detailsElement.setAttribute('open', 'true');
      itemElement.classList.add('is-selected');
      document.getElementById('active-vertical-tab')?.remove();
      const marker = document.createElement('span');
      marker.id = 'active-vertical-tab';
      marker.className = 'visually-hidden';
      marker.textContent = Drupal.t('(active tab)');
      linkElement.append(marker);
    },

    /**
     * Renders the current summary.
     */
    updateSummary() {
      this.summaryElement.innerHTML = Drupal.form.getSummary(
        this.detailsElement,
      );
    },

    /**
     * Shows this tab and focuses its pane.
     */
    tabShow() {
      const { detailsElement, itemElement } = this;
      show(itemElement);
      const wrapper = itemElement.closest('.js-form-type-vertical-tabs');
      if (wrapper) {
        show(wrapper);
      }
      markFirstTab(itemElement);
      detailsElement.classList.remove('vertical-tab--hidden');
      show(detailsElement);
      this.focus();
      return this;
    },

    /**
     * Hides this tab and opens the first available sibling pane.
     */
    tabHide() {
      const { detailsElement, itemElement } = this;
      hide(itemElement);
      markFirstTab(itemElement);
      detailsElement.classList.add('vertical-tab--hidden');
      hide(detailsElement);
      detailsElement.removeAttribute('open');
      const first = siblingPanes(detailsElement).find(
        (pane) => !pane.classList.contains('vertical-tab--hidden'),
      );
      if (first) {
        tabs.get(first).focus();
      } else {
        const wrapper = itemElement.closest('.js-form-type-vertical-tabs');
        if (wrapper) {
          hide(wrapper);
        }
      }
      return this;
    },
  };
  Drupal.verticalTab = VerticalTab;

  /**
   * Themes a tab using native title, item, link and summary elements.
   */
  Drupal.theme.verticalTabElements = ({ title }) => {
    const template = document.createElement('template');
    template.innerHTML =
      '<li class="vertical-tabs__menu-item" tabindex="-1"><a href="#"><strong class="vertical-tabs__menu-item-title"></strong><span class="vertical-tabs__menu-item-summary"></span></a></li>';
    const itemElement = template.content.firstElementChild;
    const linkElement = itemElement.firstElementChild;
    const titleElement = linkElement.firstElementChild;
    const summaryElement = linkElement.lastElementChild;
    titleElement.textContent = title;
    return { itemElement, linkElement, titleElement, summaryElement };
  };

  document.dispatchEvent(new Event('drupal:vertical-tabs-ready'));
})(Drupal, drupalSettings);
