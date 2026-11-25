/**
 * Renders an entity title and its optional description.
 *
 * The suggestion endpoint provides HTML sanitized for display.
 *
 * @param {HTMLElement} option
 *   The option to populate.
 * @param {object} item
 *   The entity suggestion.
 */
function renderOption(option, item) {
  option.classList.add('entity-link-suggestions-result-line');
  const wrapper = document.createElement('div');
  wrapper.className = 'entity-link-suggestions-result-line-wrapper';
  const title = document.createElement('span');
  title.className = 'entity-link-suggestions-result-line--title';
  title.innerHTML = item.label;
  wrapper.append(title);
  if (item.description !== undefined) {
    const description = document.createElement('span');
    description.className = 'entity-link-suggestions-result-line--description';
    description.innerHTML = item.description;
    wrapper.append(description);
  }
  option.append(wrapper);
}

export default function initializeAutocomplete(
  input,
  { url, query, selectItem },
) {
  return Drupal.autocomplete(input, url, {
    query,
    selectItem,
    renderOption,
    searchOnClick: true,
    classes: ['ck-reset_all-excluded', 'entity-link-suggestions-autocomplete'],
    fallbackContainer: input.closest('.ck-link-form'),
  });
}
