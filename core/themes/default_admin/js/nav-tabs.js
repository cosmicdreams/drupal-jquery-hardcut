/**
 * @file
 * Responsive navigation tabs.
 *
 * Tabs with the is-collapsible class can collapse into a menu.
 */
((Drupal) => {
  /**
   * Initializes one responsive tabs menu.
   *
   * @param {HTMLElement} tab
   *   The navigation element.
   */
  function init(tab) {
    const target = tab.querySelector('[data-drupal-nav-tabs-target]');
    if (!target) {
      return;
    }
    const active = target.querySelector('.js-active-tab');

    const toggleOrder = (reset) => {
      if (!active) {
        return;
      }
      const current = Array.from(active.parentElement.children).indexOf(active);
      const original = Number(active.dataset.originalOrder);

      // Do not change order if already first or if already reset.
      if (original === 0 || reset === (current === original)) {
        return;
      }

      if (reset && current !== original) {
        target
          .querySelector(`[data-original-order="${original - 1}"]`)
          ?.after(active);
      } else if (!reset && current === original) {
        target.querySelector('[data-original-order="0"]')?.before(active);
      }
    };

    const toggleCollapsed = ({ matches }) => {
      const buttons = tab.querySelectorAll('button');
      if (matches) {
        if (
          tab.classList.contains('is-horizontal') &&
          !tab.hasAttribute('data-width')
        ) {
          let width = 0;
          target.querySelectorAll('.js-tabs-link').forEach((link) => {
            width += link.getBoundingClientRect().width;
          });
          tab.dataset.width = width;
        }

        // Collapse when the tabs are wider than the navigation container.
        const isHorizontal =
          Number(tab.dataset.width) <= tab.getBoundingClientRect().width;
        tab.classList.toggle('is-horizontal', isHorizontal);
        buttons.forEach((button) => button.removeAttribute('aria-expanded'));
        toggleOrder(isHorizontal);
      } else {
        toggleOrder(false);
        buttons.forEach((button) =>
          button.setAttribute('aria-expanded', 'false'),
        );
      }
    };

    tab.classList.add('is-horizontal-enabled');

    target.querySelectorAll('.js-tab').forEach((item) => {
      item.dataset.originalOrder = Array.from(
        item.parentElement.children,
      ).indexOf(item);
    });

    const binding = {
      target: tab,
      type: 'click',
      namespace: 'tabs',
      selector: '[data-drupal-nav-tabs-trigger]',
      navTabs: true,
      reservesLegacySlot: true,
      listener() {
        const expanded = target.classList.toggle('is-open');
        target.querySelectorAll('button').forEach((button) => {
          button.setAttribute('aria-expanded', String(expanded));
        });
      },
      stop() {
        tab.removeEventListener('click', onClick);
        unregister();
      },
    };

    function onClick(event) {
      if (binding.handlesNativeEvent?.(event) || event.button >= 1) {
        return;
      }
      const trigger = event.target.closest?.(binding.selector);
      if (trigger && trigger !== tab && tab.contains(trigger)) {
        binding.listener();
      }
    }

    tab.addEventListener('click', onClick);
    const unregister = Drupal.eventBindings.register(binding);
    const mql = window.matchMedia('(min-width: 48em)');
    mql.addEventListener('change', toggleCollapsed);
    toggleCollapsed(mql);
  }

  /**
   * Initialize the tabs.
   */
  Drupal.behaviors.navTabs = {
    attach(context) {
      once(
        'nav-tabs',
        '[data-drupal-nav-tabs].is-collapsible',
        context,
      ).forEach(init);
    },
  };
})(Drupal);
