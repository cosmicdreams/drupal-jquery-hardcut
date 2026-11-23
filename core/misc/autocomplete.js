/**
 * @file
 * Accessible autocomplete for Drupal forms and CKEditor link suggestions.
 */

((Drupal, once, { autoUpdate, computePosition, flip, hide, shift, size }) => {
  const controllers = new Map();
  let nextId = 0;

  // One observer covers removals that bypass Drupal's behavior lifecycle.
  const removals = new MutationObserver((records) => {
    if (records.some(({ removedNodes }) => removedNodes.length)) {
      controllers.forEach(({ list, destroy }, input) => {
        if (!input.isConnected || !list.isConnected) {
          destroy();
        }
      });
    }
  });

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
      fallbackContainer,
    } = options;
    const list = document.createElement('ul');
    const supportsPopover = typeof list.showPopover === 'function';
    const cache = new Map();
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
    let cancelAnnouncement;
    let stopPositioning;
    let active = -1;
    let rows = [];
    let resultsValue;
    let resultsKey;

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

    function collapse() {
      cancelAnnouncement?.();
      cancelAnnouncement = undefined;
      list.style.visibility = 'hidden';
      activate(-1);
      input.setAttribute('aria-expanded', 'false');
    }

    function cancelSearch() {
      clearTimeout(timer);
      timer = undefined;
      request?.abort();
      request = undefined;
      loading(false);
    }

    function clearResults() {
      stopPositioning?.();
      stopPositioning = undefined;
      placement = undefined;
      if (supportsPopover && list.matches(':popover-open')) {
        list.hidePopover();
      }
      list.hidden = true;
      collapse();
      list.replaceChildren();
      rows = [];
      resultsValue = undefined;
      resultsKey = undefined;
    }

    function close() {
      cancelSearch();
      clearResults();
    }

    function destroy() {
      if (signal.aborted) {
        return;
      }
      close();
      cache.clear();
      listeners.abort();
      list.remove();
      previous.forEach(([name, original]) => {
        if (original === null) {
          input.removeAttribute(name);
        } else {
          input.setAttribute(name, original);
        }
      });
      once.remove('autocomplete', input);
      controllers.delete(input);
      if (!controllers.size) {
        removals.disconnect();
      }
    }

    function activate(index, scroll = false) {
      rows[active]?.option.setAttribute('aria-selected', 'false');
      active = index;
      const option = rows[index]?.option;
      if (!option) {
        input.removeAttribute('aria-activedescendant');
        return;
      }
      option.setAttribute('aria-selected', 'true');
      input.setAttribute('aria-activedescendant', option.id);
      if (scroll) {
        // Keep the field and its scroll containers in place.
        const { top, bottom } = option.getBoundingClientRect();
        const listTop = list.getBoundingClientRect().top + list.clientTop;
        list.scrollTop +=
          Math.min(0, top - listTop) +
          Math.max(0, bottom - listTop - list.clientHeight);
      }
    }

    function move(direction, byPage) {
      if (active < 0) {
        activate(byPage || direction > 0 ? 0 : rows.length - 1, true);
        return;
      }
      let next = active + direction;
      if (next < 0 || next >= rows.length) {
        activate(-1);
        return;
      }
      if (byPage) {
        const edge =
          rows[active].option.getBoundingClientRect().top +
          direction * list.clientHeight;
        while (
          rows[next + direction] &&
          direction * (rows[next].option.getBoundingClientRect().top - edge) < 0
        ) {
          next += direction;
        }
      }
      activate(next, true);
    }

    function canSearch() {
      return (
        input.isConnected &&
        list.isConnected &&
        document.activeElement === input &&
        !input.matches(':disabled, [readonly]')
      );
    }

    function terms() {
      return multiple ? splitValues(input.value) : [input.value];
    }

    function searchTerm() {
      const term = terms().at(-1);
      const denyList = input.dataset.autocompleteFirstCharacterDenylist || '';
      return denyList.includes(term[0]) ? '' : term;
    }

    function select(index) {
      if (index < 0 || resultsValue !== input.value) {
        return false;
      }
      const { item } = rows[index];
      close();
      selectItem(item, terms());
      return true;
    }

    function show(items, value) {
      const key = JSON.stringify(items);
      if (key === resultsKey) {
        resultsValue = value;
        return;
      }
      clearResults();
      resultsKey = key;
      resultsValue = value;
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

      if (!rows.length) {
        cancelAnnouncement = Drupal.announce(Drupal.t('No search results.'));
        return;
      }

      // Keep the popover in the field's DOM subtree, including inside modals
      // and editor balloons. Without popovers, use a fixed overlay in the
      // containing dialog or page to escape scrolling containers, unless the
      // widget needs its list inside a focus-managed container.
      if (!supportsPopover) {
        (fallbackContainer || input.closest('dialog') || document.body).append(
          list,
        );
      }
      list.hidden = false;
      list.style.visibility = 'hidden';
      list.style.removeProperty('--autocomplete-available-width');
      list.style.removeProperty('--autocomplete-available-height');
      list.scrollTop = 0;
      if (supportsPopover) {
        list.showPopover();
      }
      let announced = false;
      const placementSession = {};
      placement = placementSession;
      const position = () => {
        if (!input.isConnected || !list.isConnected) {
          destroy();
          return;
        }
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
        }).then(({ x, y, placement: side, middlewareData }) => {
          if (placement !== placementSession) {
            return;
          }
          if (
            !input.getClientRects().length ||
            (supportsPopover && !list.matches(':popover-open'))
          ) {
            close();
            return;
          }
          if (middlewareData.hide.referenceHidden) {
            // Keep tracking the anchor so scrolling back can restore the
            // suggestions without restoring an active option.
            collapse();
            return;
          }
          list.style.left = `${x}px`;
          list.style.top = `${y}px`;
          list.classList.toggle(
            'autocomplete-list--above',
            side.startsWith('top'),
          );
          if (list.style.visibility === 'hidden') {
            list.style.visibility = '';
            input.setAttribute('aria-expanded', 'true');
          }
          if (!announced) {
            announced = true;
            cancelAnnouncement = Drupal.announce(
              Drupal.formatPlural(
                rows.length,
                '1 result is available. Use the up and down arrow keys to navigate.',
                '@count results are available. Use the up and down arrow keys to navigate.',
              ),
            );
          }
        });
      };
      stopPositioning = autoUpdate(input, list, position, {
        animationFrame: true,
      });
    }

    async function search() {
      timer = undefined;
      if (!canSearch()) {
        return;
      }
      const value = input.value;
      const term = searchTerm();
      if (!term) {
        clearResults();
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
        if (!current.signal.aborted && canSearch() && input.value === value) {
          const taken = terms().slice(0, -1);
          show(
            items.filter((item) => !taken.includes(item.value)),
            value,
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
      cancelSearch();
      if (!composing && searchTerm()) {
        // Keep the old list visible while waiting for results for the new
        // value. Its options cannot be selected until the results match.
        activate(-1);
        timer = setTimeout(search, 300);
      } else {
        clearResults();
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
    document.addEventListener(
      'pointerdown',
      ({ target }) => {
        if (
          (resultsValue !== undefined || timer !== undefined || request) &&
          target !== input &&
          !list.contains(target)
        ) {
          close();
        }
      },
      { capture: true, signal },
    );
    window.addEventListener(
      'dialog:beforeclose',
      ({ target }) => {
        if (target.contains(input)) {
          close();
        }
      },
      { signal },
    );
    list.addEventListener(
      'toggle',
      ({ newState }) => {
        if (
          newState === 'closed' &&
          placement &&
          !list.matches(':popover-open')
        ) {
          close();
        }
      },
      { signal },
    );
    if (searchOnClick) {
      input.addEventListener(
        'click',
        () => {
          cancelSearch();
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
        if (composing || event.isComposing || !canSearch()) {
          return;
        }
        const expanded =
          input.getAttribute('aria-expanded') === 'true' &&
          (!supportsPopover || list.matches(':popover-open'));
        if (key === 'Tab') {
          if (!expanded || !select(active)) {
            close();
          }
          return;
        }
        const byPage = key === 'PageDown' || key === 'PageUp';
        if (byPage || key === 'ArrowDown' || key === 'ArrowUp') {
          const direction = key === 'ArrowDown' || key === 'PageDown' ? 1 : -1;
          if (!expanded) {
            if (placement || !searchTerm()) {
              return;
            }
            close();
            search();
          } else if (resultsValue === input.value) {
            move(direction, byPage);
          } else {
            return;
          }
        } else if (key === 'Escape') {
          close();
          if (!expanded) {
            return;
          }
        } else if (key !== 'Enter' || !expanded || !select(active)) {
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
        const index =
          option && resultsValue === input.value
            ? Number(option.dataset.optionIndex)
            : -1;
        if (index !== active) {
          activate(index);
        }
      },
      { signal },
    );
    list.addEventListener('pointerleave', () => activate(-1), { signal });
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

    if (!controllers.size) {
      removals.observe(document, { childList: true, subtree: true });
    }
    controllers.set(input, { list, destroy });
    return { close, destroy };
  };

  Drupal.behaviors.autocomplete = {
    attach(context) {
      const selector = 'input.form-autocomplete';
      const inputs = context.matches?.(selector) ? context : selector;
      once('autocomplete', inputs, context).forEach((input) => {
        Drupal.autocomplete(input, input.dataset.autocompletePath, {
          multiple: true,
        });
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
        });
      }
    },
  };
})(Drupal, once, FloatingUIDOM);
