/**
 * @file
 * Adds bulk selection to the media library.
 */

((Drupal, once) => {
  /**
   * Adds a checkbox to select all items in each media library view.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.MediaLibrarySelectAll = {
    attach(context) {
      once(
        'media-library-select-all',
        '.js-media-library-view[data-view-display-id="page"]',
        context,
      ).forEach((view) => {
        const firstItem = view.querySelector('.js-media-library-item');
        if (!firstItem) {
          return;
        }
        const label = document.createElement('label');
        label.className = 'media-library-select-all';
        label.textContent = Drupal.t('Select all media');
        label.insertAdjacentHTML('afterbegin', Drupal.theme('checkbox'));
        const checkbox = label.querySelector('input');
        checkbox.addEventListener('change', () => {
          const items = view.querySelectorAll(
            '.js-media-library-item input[type="checkbox"]',
          );
          for (const input of items) {
            input.checked = checkbox.checked;
            input.dispatchEvent(new Event('change', { bubbles: true }));
          }
          Drupal.announce(
            checkbox.checked
              ? Drupal.t('All @count items selected', {
                  '@count': items.length,
                })
              : Drupal.t('Zero items selected'),
          );
        });
        firstItem.before(label);
      });
    },
  };
})(Drupal, once);
