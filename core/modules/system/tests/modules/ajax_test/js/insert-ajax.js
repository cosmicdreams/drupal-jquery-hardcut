/**
 * @file
 * Attaches Ajax insertion behavior to the test links.
 */

((Drupal) => {
  Drupal.behaviors.insertTest = {
    attach(context) {
      const selector = '.ajax-insert, .ajax-insert-inline';
      once('ajax-insert', selector, context).forEach((link) => {
        link.addEventListener('click', (event) => {
          event.preventDefault();
          Drupal.ajax({
            url: link.getAttribute('href'),
            wrapper: link.classList.contains('ajax-insert-inline')
              ? 'ajax-target-inline'
              : 'ajax-target',
            base: false,
            element: false,
            method: link.dataset.method,
            effect: link.dataset.effect,
          }).execute();
        });
      });
      context.classList?.add('processed');
    },
  };
})(Drupal);
