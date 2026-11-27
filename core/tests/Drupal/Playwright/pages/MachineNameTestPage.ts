import { DrupalPage } from './DrupalPage';

type MachineNameBehavior = {
  transliterate(
    source: string,
    settings: { replace_pattern: string; replace: string; maxlength: number },
  ): string;
};

export class MachineNameTestPage extends DrupalPage {
  async transliterate(
    machineName: string,
    replacePattern: string,
    replaceChar: string,
    maxlength: number,
  ): Promise<string> {
    return this.page.evaluate(
      ({ source, settings }) => {
        const { Drupal } = window as unknown as {
          Drupal: { behaviors: { machineName: MachineNameBehavior } };
        };
        return Drupal.behaviors.machineName.transliterate(source, settings);
      },
      {
        source: machineName,
        settings: {
          replace_pattern: replacePattern,
          replace: replaceChar,
          maxlength,
        },
      },
    );
  }
}
