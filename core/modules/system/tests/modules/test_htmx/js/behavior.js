((Drupal, once) => {
  Drupal.behaviors.htmx_test = {
    attach(context, settings) {
      once('htmx-init', '.ajax-content', context).forEach((el) => {
        el.innerText = 'initialized';
      });
      document.body.dataset.htmxBehaviorTest = 'attached';
    },
    detach(context, settings, trigger) {
      if (
        trigger === 'unload' &&
        once.remove('htmx-init', '.ajax-content', context).length > 0
      ) {
        document.body.dataset.htmxBehaviorTest = 'detached';
      }
    },
  };
})(Drupal, once);
