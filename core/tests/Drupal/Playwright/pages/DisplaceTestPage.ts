import { DrupalPage } from './DrupalPage';

type Offsets<T = number> = {
  top: T;
  right: T;
  bottom: T;
  left: T;
};

declare const Drupal: { displace(): Offsets };

const testElements = `
  <div data-offset-top style="background-color:red; height:110px; left:0;
    position:fixed; right:0; top:90px; width:100%"></div>
  <div data-offset-right style="background-color:blue; bottom:0; height:100%;
    position:fixed; right:10px; top:0; width:100px"></div>
  <div data-offset-bottom style="background-color:yellow; bottom:45px;
    height:100px; left:0; position:fixed; right:0; width:100%"></div>
  <div data-offset-left style="background-color:orange; bottom:0; height:100%;
    left:10px; position:fixed; top:0; width:300px"></div>
`;

export class DisplaceTestPage extends DrupalPage {
  async addOffsetElements(): Promise<void> {
    await this.page.evaluate((html) => {
      const container = document.createElement('div');
      container.innerHTML = html;
      document.body.append(container);
    }, testElements);
  }

  async displace(): Promise<Offsets> {
    return this.page.evaluate(() => ({ ...Drupal.displace() }));
  }

  async cssOffsets(): Promise<Offsets<string>> {
    return this.document.evaluate((root) => {
      const styles = getComputedStyle(root);
      return {
        top: styles.getPropertyValue('--drupal-displace-offset-top'),
        right: styles.getPropertyValue('--drupal-displace-offset-right'),
        bottom: styles.getPropertyValue('--drupal-displace-offset-bottom'),
        left: styles.getPropertyValue('--drupal-displace-offset-left'),
      };
    });
  }

  async displaceAndCaptureEvents(): Promise<Offsets[]> {
    return this.page.evaluate(() => {
      const events: Offsets[] = [];
      const capture = (event: Event) => {
        if (event instanceof CustomEvent) {
          events.push({ ...event.detail });
        }
      };
      document.addEventListener('drupalViewportOffsetChange', capture);
      try {
        Drupal.displace();
        return events;
      } finally {
        document.removeEventListener('drupalViewportOffsetChange', capture);
      }
    });
  }
}
