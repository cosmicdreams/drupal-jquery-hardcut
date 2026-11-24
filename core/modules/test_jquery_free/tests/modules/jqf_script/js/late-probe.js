/**
 * @file
 * Reads the canary after a script assigns undefined.
 */

if (!new URLSearchParams(location.search).has('preset')) {
  Reflect.set(window, 'jQuery', undefined);
  for (let read = 0; read < 3; read += 1) {
    document.body.dataset.lateJquery = typeof Reflect.get(window, 'jQuery');
  }
  document.body.dataset.lateAlias = typeof Reflect.get(window, '$');
}

if (new URLSearchParams(location.search).has('define')) {
  Reflect.set(window, 'jQuery', null);
  Reflect.set(window, '$', {
    get fn() {
      throw new Error('The canary must not inspect the assigned value');
    },
  });
}
