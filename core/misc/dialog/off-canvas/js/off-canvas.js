/**
 * @file
 * Positions and measures the native off-canvas dialog.
 */

((Drupal, displace) => {
  const sessions = new WeakMap();
  const overlay = window.matchMedia('(max-width: 48rem)');

  function isOffCanvas(content) {
    return content.id === 'drupal-off-canvas';
  }

  Drupal.offCanvas = { isOffCanvas };

  window.addEventListener(
    'dialog:aftercreate',
    ({ target, dialog, settings }) => {
      if (!isOffCanvas(target)) {
        return;
      }
      const tray = dialog.element;
      const canvas = document.querySelector('[data-off-canvas-main-canvas]');
      const side = settings.drupalOffCanvasPosition === 'side';
      const edge = side
        ? tray.matches(':dir(rtl)')
          ? 'left'
          : 'right'
        : 'top';
      const padding = `padding-${edge}`;
      const originalPadding = canvas?.style.getPropertyValue(padding);
      const offset = `data-offset-${edge}`;
      const listeners = new AbortController();
      const { signal } = listeners;
      tray.id = 'drupal-off-canvas-wrapper';
      tray.classList.toggle('drupal-dialog-empty-title', !settings.title);
      document.body.classList.add('js-off-canvas-dialog-open');

      const handle = side ? document.createElement('div') : null;
      const minimumWidth = 150;
      const maximumWidth = () => document.documentElement.clientWidth;

      function displaceCanvas() {
        const rect = tray.getBoundingClientRect();
        const size = side ? (overlay.matches ? 0 : rect.width) : rect.height;
        if (
          canvas &&
          (size || canvas.style.getPropertyValue(padding) !== originalPadding)
        ) {
          canvas.style.setProperty(
            padding,
            size ? `${size}px` : originalPadding,
          );
        }
        if (handle) {
          handle.setAttribute('aria-valuemax', maximumWidth());
          handle.setAttribute('aria-valuenow', Math.round(rect.width));
        }
        tray.setAttribute(offset, size);
        displace();
      }

      if (handle) {
        // Resize from the inner edge so the tray stays anchored at the viewport.
        handle.tabIndex = 0;
        handle.setAttribute('role', 'separator');
        handle.setAttribute('aria-orientation', 'vertical');
        handle.setAttribute('aria-controls', target.id);
        handle.setAttribute('aria-valuemin', minimumWidth);
        handle.className = 'drupal-dialog-resize';
        handle.setAttribute('aria-label', Drupal.t('Resize sidebar'));
        tray.append(handle);
        const direction = edge === 'right' ? -1 : 1;
        function setWidth(width) {
          tray.style.width = `${Math.max(minimumWidth, Math.min(width, maximumWidth()))}px`;
        }
        handle.addEventListener('pointerdown', (event) => {
          if (event.button !== 0) {
            return;
          }
          const start = event.clientX;
          const width = tray.getBoundingClientRect().width;
          handle.setPointerCapture(event.pointerId);
          handle.onpointermove = (move) =>
            setWidth(width + direction * (move.clientX - start));
        });
        handle.addEventListener('lostpointercapture', () => {
          handle.onpointermove = null;
        });
        handle.addEventListener('keydown', (event) => {
          const width = tray.getBoundingClientRect().width;
          const widths = {
            ArrowLeft: width - direction * 10,
            ArrowRight: width + direction * 10,
            Home: minimumWidth,
            End: maximumWidth(),
          };
          if (Object.hasOwn(widths, event.key)) {
            event.preventDefault();
            setWidth(widths[event.key]);
          }
        });
      }

      target.addEventListener('dialogContentResize', displaceCanvas, {
        signal,
      });
      window.addEventListener('resize', displaceCanvas, { signal });
      displaceCanvas();
      sessions.set(target, () => {
        listeners.abort();
        handle?.remove();
        tray.removeAttribute(offset);
        document.body.classList.remove('js-off-canvas-dialog-open');
        if (canvas) {
          canvas.style.setProperty(padding, originalPadding);
        }
        displace();
      });
    },
  );

  window.addEventListener('dialog:beforeclose', ({ target }) => {
    sessions.get(target)?.();
    sessions.delete(target);
  });
})(Drupal, Drupal.displace);
