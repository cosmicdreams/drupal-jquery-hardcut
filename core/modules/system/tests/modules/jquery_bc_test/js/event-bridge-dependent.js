/**
 * @file
 * Exercises the event bridge as soon as a dependent classic script runs.
 * @param {object} Drupal
 *   The Drupal runtime.
 * @param {function(HTMLElement): object} instance
 *   The jQuery instance supplied by the explicit library.
 */

(function (Drupal, instance) {
  const target = document.getElementById('event-bridge-fixture');
  const type = 'compatibility:probe';
  let calls = 0;
  const listener = () => {
    calls += 1;
    return 'ready';
  };
  target.addEventListener(type, listener);
  const unregister = Drupal.eventBindings.register({ target, type, listener });

  const result = instance(target).triggerHandler(type);
  target.dataset.bridgeTrace = JSON.stringify([result, calls]);
  unregister();
  target.removeEventListener(type, listener);
})(Drupal, window.jQuery);
