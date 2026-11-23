/**
 * @file
 * Accessible autocomplete for Drupal forms and CKEditor link suggestions.
 */

((Drupal, once, { autoUpdate, computePosition, flip, hide, shift, size }) => {
  const controllers = new WeakMap();
  const cache = new Map();
  let nextId = 0;

  function splitValues(value) {
    const terms = [];
    let quoted = false;
    let start = 0;
    for (let index = 0; index < value.length; index++) {
      if (value[index] === '"') {
        quoted = !quoted;
      } else if (value[index] === ',' && !quoted) {
        terms.push(value.slice(start, index).trim());
        start = index + 1;
      }
    }
    terms.push(value.slice(start).trim());
    return terms;
  }

  /**
   * Attaches a combobox to an input using a Drupal suggestion endpoint.
   *
   * @param {HTMLInputElement} input
   *   The input that receives the suggestions.
   * @param {string} url
   *   The suggestion endpoint.
   * @param {object} options
   *   Query, rendering, and selection options for the input.
   * @return {object}
   *   The combobox lifecycle controls.
   */
  Drupal.autocomplete = (input, url, options = {}) => {
    const {
      multiple = false,
      query = {},
      renderOption = (option, item) => {
        option.innerHTML = item.label;
      },
      selectItem = (item, terms) => {
        input.value = [...terms.slice(0, -1), item.value].join(', ');
      },
      searchOnClick = false,
      classes = [],
    } = options;
    const list = document.createElement('ul');
    const supportsPopover = typeof list.showPopover === 'function';
    const listeners = new AbortController();
    const { signal } = listeners;
    nextId += 1;
    const attributes = {
      role: 'combobox',
      'aria-autocomplete': 'list',
      'aria-controls': `drupal-autocomplete-${nextId}`,
      'aria-expanded': 'false',
      autocomplete: 'off',
    };
    const previous = Object.entries(attributes).map(([name, value]) => {
      const original = input.getAttribute(name);
      input.setAttribute(name, value);
      return [name, original];
    });
    let composing = false;
    let timer;
    let request;
    let stopPositioning;
    let active = -1;
    let rows = [];

    list.id = attributes['aria-controls'];
    list.classList.add('autocomplete-list', ...classes);
    list.setAttribute('role', 'listbox');
    list.tabIndex = -1;
    const label = input.labels?.[0]?.textContent.trim();
    list.setAttribute('aria-label', label || Drupal.t('Suggestions'));
    list.hidden = true;
    if (supportsPopover) {
      list.popover = 'manual';
    }
    input.parentElement.append(list);
    let placement;

    function loading(value) {
      input.classList.toggle('is-autocompleting', value);
    }

    function close() {
      clearTimeout(timer);
      timer = undefined;
      request?.abort();
      request = undefined;
      loading(false);
      stopPositioning?.();
      stopPositioning = undefined;
      placement = undefined;
      if (supportsPopover && list.matches(':popover-open')) {
        list.hidePopover();
      }
      list.hidden = true;
      list.replaceChildren();
      rows = [];
      active = -1;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    }

    function activate(index, scroll = false) {
      rows[active]?.option.setAttribute('aria-selected', 'false');
      const { option } = rows[index];
      active = index;
      option.setAttribute('aria-selected', 'true');
      input.setAttribute('aria-activedescendant', option.id);
      if (scroll) {
        option.scrollIntoView({ block: 'nearest' });
      }
    }

    function terms() {
      return multiple ? splitValues(input.value) : [input.value];
    }

    function select(index) {
      const { item } = rows[index];
      close();
      selectItem(item, terms());
    }

    function show(items, direction) {
      const groups = new Map();
      items.forEach((item) => {
        const group = item.group || '';
        if (!groups.has(group)) {
          groups.set(group, []);
        }
        groups.get(group).push(item);
      });
      groups.forEach((itemsInGroup, group) => {
        let parent = list;
        if (group) {
          const wrapper = document.createElement('li');
          wrapper.setAttribute('role', 'presentation');
          const heading = document.createElement('div');
          heading.className = 'autocomplete-group';
          heading.textContent = group;
          heading.setAttribute('aria-hidden', 'true');
          parent = document.createElement('ul');
          parent.setAttribute('role', 'group');
          parent.setAttribute('aria-label', group);
          wrapper.append(heading, parent);
          list.append(wrapper);
        }
        itemsInGroup.forEach((item) => {
          const option = document.createElement('li');
          const index = rows.length;
          option.id = `${list.id}-option-${index}`;
          option.dataset.optionIndex = index;
          option.setAttribute('role', 'option');
          option.setAttribute('aria-selected', 'false');
          renderOption(option, item);
          rows.push({ option, item });
          parent.append(option);
        });
      });

      Drupal.announce(
        Drupal.formatPlural(
          rows.length,
          '1 result is available.',
          '@count results are available.',
        ),
      );
      if (!rows.length) {
        return;
      }

      // Keep the popover in the field's DOM subtree, including inside modals
      // and editor balloons. Without popovers, use a fixed overlay in the
      // containing dialog or page to escape scrolling containers.
      if (!supportsPopover) {
        (input.closest('dialog') || document.body).append(list);
      }
      list.hidden = false;
      list.style.visibility = 'hidden';
      list.style.removeProperty('--autocomplete-available-width');
      list.style.removeProperty('--autocomplete-available-height');
      list.scrollTop = 0;
      if (supportsPopover) {
        list.showPopover();
      }
      input.setAttribute('aria-expanded', 'true');
      const placementSession = {};
      placement = placementSession;
      const position = () =>
        computePosition(input, list, {
          strategy: 'fixed',
          placement: 'bottom-start',
          middleware: [
            flip({ rootBoundary: 'viewport' }),
            shift({ padding: 4 }),
            size({
              padding: 4,
              apply({ availableWidth, availableHeight, rects }) {
                if (placement === placementSession) {
                  list.style.setProperty(
                    '--autocomplete-available-width',
                    `${Math.max(0, availableWidth)}px`,
                  );
                  list.style.setProperty(
                    '--autocomplete-available-height',
                    `${Math.max(0, availableHeight)}px`,
                  );
                  list.style.minWidth = `${Math.min(rects.reference.width, Math.max(0, availableWidth))}px`;
                }
              },
            }),
            hide(),
          ],
        }).then(({ x, y, middlewareData }) => {
          if (placement === placementSession) {
            list.style.left = `${x}px`;
            list.style.top = `${y}px`;
            list.style.visibility = middlewareData.hide.referenceHidden
              ? 'hidden'
              : '';
            if (direction && active < 0) {
              activate(direction > 0 ? 0 : rows.length - 1, true);
            }
          }
        });
      stopPositioning = autoUpdate(input, list, position, {
        animationFrame: true,
      });
    }

    async function search(direction) {
      timer = undefined;
      const value = input.value;
      const term = terms().at(-1);
      const denyList = input.dataset.autocompleteFirstCharacterDenylist || '';
      if (!term.length || denyList.includes(term[0])) {
        return;
      }
      const endpoint = new URL(url, document.baseURI);
      Object.entries(query).forEach(([key, param]) => {
        endpoint.searchParams.set(key, param ?? '');
      });
      endpoint.searchParams.set('q', term);
      const key = endpoint.href;
      const current = new AbortController();
      request = current;
      try {
        let items = cache.get(key);
        if (!items) {
          loading(true);
          const response = await fetch(endpoint, {
            signal: current.signal,
            headers: { Accept: 'application/json' },
          });
          if (!response.ok) {
            return;
          }
          const data = await response.json();
          items = data.suggestions ?? data;
          if (!current.signal.aborted) {
            cache.set(key, items);
          }
        }
        if (
          !current.signal.aborted &&
          input.isConnected &&
          document.activeElement === input &&
          input.value === value
        ) {
          const taken = terms().slice(0, -1);
          show(
            items.filter((item) => !taken.includes(item.value)),
            direction,
          );
        }
      } catch (error) {
        if (!current.signal.aborted) {
          console.error(error);
        }
      } finally {
        if (request === current) {
          request = undefined;
          loading(false);
        }
      }
    }

    function schedule() {
      close();
      if (!composing) {
        timer = setTimeout(search, 300);
      }
    }

    input.addEventListener('input', schedule, { signal });
    input.addEventListener(
      'compositionstart',
      () => {
        composing = true;
        close();
      },
      { signal },
    );
    input.addEventListener(
      'compositionend',
      () => {
        composing = false;
        schedule();
      },
      { signal },
    );
    input.addEventListener('blur', close, { signal });
    if (searchOnClick) {
      input.addEventListener(
        'click',
        () => {
          close();
          if (!composing) {
            search();
          }
        },
        { signal },
      );
    }
    input.addEventListener(
      'keydown',
      (event) => {
        const { key } = event;
        if (composing || event.isComposing) {
          return;
        }
        if (key === 'Tab') {
          if (active >= 0) {
            select(active);
          } else {
            close();
          }
          return;
        }
        if (key === 'ArrowDown' || key === 'ArrowUp') {
          const direction = key === 'ArrowDown' ? 1 : -1;
          if (!rows.length) {
            if (!terms().at(-1).length) {
              return;
            }
            close();
            search(direction);
          } else {
            const next =
              active < 0 && direction < 0
                ? rows.length - 1
                : (active + direction + rows.length) % rows.length;
            activate(next, true);
          }
        } else if (key === 'Enter' && active >= 0) {
          select(active);
        } else if (key === 'Escape' && (rows.length || request || timer)) {
          close();
        } else {
          return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
      },
      { capture: true, signal },
    );

    // Keep DOM focus on the input during pointer selection.
    list.addEventListener('mousedown', (event) => event.preventDefault(), {
      signal,
    });
    list.addEventListener(
      'pointermove',
      (event) => {
        const option = event.target.closest('[data-option-index]');
        if (option && Number(option.dataset.optionIndex) !== active) {
          activate(Number(option.dataset.optionIndex));
        }
      },
      { signal },
    );
    list.addEventListener(
      'click',
      (event) => {
        const option = event.target.closest('[data-option-index]');
        if (option) {
          select(Number(option.dataset.optionIndex));
        }
      },
      { signal },
    );

    return {
      close,
      destroy() {
        close();
        listeners.abort();
        list.remove();
        previous.forEach(([name, original]) => {
          if (original === null) {
            input.removeAttribute(name);
          } else {
            input.setAttribute(name, original);
          }
        });
      },
    };
  };

  Drupal.behaviors.autocomplete = {
    attach(context) {
      const selector = 'input.form-autocomplete';
      const inputs = context.matches?.(selector) ? context : selector;
      once('autocomplete', inputs, context).forEach((input) => {
        controllers.set(
          input,
          Drupal.autocomplete(input, input.dataset.autocompletePath, {
            multiple: true,
          }),
        );
      });
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        const selector = 'input.form-autocomplete';
        const elements = context.matches?.(selector)
          ? once.remove('autocomplete', context)
          : once.remove('autocomplete', selector, context);
        elements.forEach((input) => {
          controllers.get(input)?.destroy();
          controllers.delete(input);
        });
      }
    },
  };
})(Drupal, once, FloatingUIDOM);
