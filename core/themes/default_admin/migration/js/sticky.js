/**
 * @file
 * Updates the displacement of the sticky administration header.
 */

((Drupal, once) => {
  const observers = new WeakMap();
  const watcherSelector = '.region-sticky-watcher';

  Drupal.behaviors.adminSticky = {
    attach(context) {
      once('adminSticky', watcherSelector, context).forEach((watcher) => {
        const regionSticky = watcher.nextElementSibling;
        if (!regionSticky?.matches('.region-sticky')) {
          return;
        }
        // Watch the marker immediately before the sticky header.
        const observer = new IntersectionObserver(
          ([e]) => {
            regionSticky.classList.toggle(
              'region-sticky--is-sticky',
              e.intersectionRatio < 1,
            );
            regionSticky.toggleAttribute(
              'data-offset-top',
              e.intersectionRatio < 1,
            );
            Drupal.displace(true);
          },
          { threshold: [1] },
        );
        observers.set(watcher, observer);
        observer.observe(watcher);
      });
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        once
          .remove('adminSticky', [
            ...(context instanceof Element ? [context] : []),
            ...context.querySelectorAll(watcherSelector),
          ])
          .forEach((watcher) => {
            observers.get(watcher)?.disconnect();
            observers.delete(watcher);
          });
      }
    },
  };
})(Drupal, once);
