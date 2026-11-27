import { expect, test } from '../fixtures/base.fixture';

/**
 * Guards transliteration with custom field settings.
 *
 * The old module-install setup clicked the two form buttons:
 * Submit module form.
 * Confirm installation of dependencies.
 *
 * Its browser callback used this directive:
 * eslint-disable-next-line func-names, prefer-arrow-callback, no-shadow
 * @see https://www.drupal.org/project/drupal/issues/2662330
 * @see https://www.drupal.org/project/drupal/issues/3413665
 * @see https://www.drupal.org/project/drupal/issues/3428652
 */
// cSpell:disable
const transliterationCases = [
  {
    machineName: 'B?!"@\\/-ob@e',
    replacePattern: '[^a-zA-Z0-9_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'b_ob_e',
  },
  {
    machineName: 'Bob@e\\0',
    replacePattern: '[^a-zA-Z0-9_.~@]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'bob@e_0',
  },
  {
    machineName: 'Bobby',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'bobby',
  },
  {
    machineName: 'ǍǎǏ',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'aai',
  },
  // The expected machine name are modified because we don't have
  // the removeDiacritics() function present in PhpTranliteration.php.
  {
    machineName: 'ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏ',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'aaaaaaaeceeeeiiii',
  },
  {
    machineName: 'àáâãäåæçèéêëìíîï',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'aaaaaaaeceeeeiiii',
  },
  {
    machineName: 'ðñòóôõö÷øùúûüýþÿ',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'dnooooo_ouuuuythy',
  },
  {
    machineName: 'ĀāĂăĄąĆćĈĉĊċČčĎď',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'aaaaaaccccccccdd',
  },
  {
    machineName: 'ĐđĒēĔĕĖėĘęĚěĜĝĞğ',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'ddeeeeeeeeeegggg',
  },
  {
    machineName: 'ĠġĢģĤĥĦħĨĩĪīĬĭĮį',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'gggghhhhiiiiiiii',
  },
  {
    machineName: 'İıĲĳĴĵĶķĸĹĺĻļĽľĿ',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'iiijijjjkkklllllll',
  },
  {
    machineName: 'ŀŁłŃńŅņŇňŉŊŋŌōŎŏ',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'lllnnnnnn_nngngoooo',
  },
  {
    machineName: 'ŐőŒœŔŕŖŗŘřŚśŜŝŞş',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'oooeoerrrrrrssssss',
  },
  {
    machineName: 'ŠšŢţŤťŦŧŨũŪūŬŭŮů',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'ssttttttuuuuuuuu',
  },
  {
    machineName: 'ŰűŲųŴŵŶŷŸŹźŻżŽž',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'uuuuwwyyyzzzzzz',
  },
  {
    machineName: 'ǐǑǒǓǔǕǖǗǘǙǚǛǜǝǞǟ',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'ioouuuuuuuuuu_aa',
  },
  {
    machineName: 'ǠǡǢǣǤǥǦǧǨǩǪǫǬǭǮǯ',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'aaaeaeggggkkoooozhzh',
  },
  {
    machineName: 'ǰǱǲǳǴǵǶǷǸǹǺǻǼǽǾǿ',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'jdzddzgghvwnnaaaeaeoo',
  },
  {
    machineName: 'ȀȁȂȃȄȅȆȇȈȉȊȋȌȍȎȏ',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'aaaaeeeeiiiioooo',
  },
  {
    machineName: 'ȐȑȒȓȔȕȖȗȘșȚțȜȝȞȟ',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'rrrruuuussttyyhh',
  },
  {
    machineName: 'ȠȡȢȣȤȥȦȧȨȩȪȫȬȭȮȯ',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'ndououzzaaeeoooooo',
  },
  {
    machineName: 'ȰȱȲȳȴȵȶȷȸȹȺȻȼȽȾȿ',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'ooyylntjdbqpacclts',
  },
  {
    machineName: 'ɀɁɂɃɄɅɆɇɈɉɊɋɌɍɎɏ',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'z_buveejjqqrryy',
  },
  // Test for maximum length of machine-name
  {
    machineName: 'This is the test for max length',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 20,
    expectedMachineName: 'this_is_the_test_for',
  },
  {
    machineName: 'Ma@Chi!~',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '_',
    maxlength: 5,
    expectedMachineName: 'ma_ch',
  },
  {
    machineName: 'Test for custom replace character',
    replacePattern: '[^a-zA-Z0-9-_.~]+',
    replaceChar: '-',
    maxlength: 64,
    expectedMachineName: 'test-for-custom-replace-character',
  },
  {
    machineName: 'Test for unusual replace pattern',
    replacePattern: '([^a-z0-9_]+)|(^custom$)',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: 'test_for_unusual_replace_pattern',
  },
  {
    machineName: 'custom',
    replacePattern: '([^a-z0-9_]+)|(^custom$)',
    replaceChar: '_',
    maxlength: 64,
    expectedMachineName: '_',
  },
  // cSpell:enable
];

test.use({ siteOptions: { modules: ['form_test'] } });

test(
  'NW-machineNameTransliterationTest-configured-transliteration',
  { tag: ['@local'] },
  async ({ machineNameTestPage }) => {
    await test.step('Open the machine-name fixture', async () => {
      await machineNameTestPage.open('form-test/machine-name');
      await expect(machineNameTestPage.heading).toHaveText(
        'Machine name fields',
      );
    });

    for (const {
      machineName,
      replacePattern,
      replaceChar,
      maxlength,
      expectedMachineName,
    } of transliterationCases) {
      await test.step(`Transliterate "${machineName}"`, async () => {
        expect
          .soft(
            await machineNameTestPage.transliterate(
              machineName,
              replacePattern,
              replaceChar,
              maxlength,
            ),
          )
          .toBe(expectedMachineName);
      });
    }
  },
);
