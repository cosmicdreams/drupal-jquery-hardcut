import { expect, test } from '../fixtures/base.fixture';

/**
 * Drupal.displace() publishes its offsets on a native document event.
 */
test.use({ siteOptions: { modules: ['js_displace'] } });

test(
  'PW-displace-native-viewport-offset-event',
  { tag: ['@core', '@local'] },
  async ({ displaceTestPage }) => {
    await test.step('Open the front page and add four fixed elements', async () => {
      await displaceTestPage.open('./');
      await displaceTestPage.addOffsetElements();
    });

    await test.step('Read the offsets from the native event detail', async () => {
      const events = await displaceTestPage.displaceAndCaptureEvents();
      expect(events).toEqual([
        { top: 200, right: 110, bottom: 145, left: 310 },
      ]);
    });
  },
);
