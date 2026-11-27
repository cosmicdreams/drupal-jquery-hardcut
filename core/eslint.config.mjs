// cspell:ignore UIDOM
import { defineConfig } from 'eslint/config';
import importPlugin from 'eslint-plugin-import';
import prettierConfig from 'eslint-plugin-prettier/recommended';
import ymlConfig from 'eslint-plugin-yml';
import jsdoc from 'eslint-plugin-jsdoc';
import js from '@eslint/js';
import tsParser from '@typescript-eslint/parser';
import globals from 'globals';
import { jqueryPlugin } from './scripts/js/jquery-ratchet.mjs';

const jqueryOnlyPaths = [
  '**/build/**/*',
  'scripts/**/*',
  'modules/ckeditor5/webpack.config.js',
  'themes/default_admin/migration/js/*.js',
];

export default defineConfig(
  {
    ignores: [
      'assets/vendor/**/*',
      'node_modules/**/*',
      '**/js_test_files/**/*',
      'modules/locale/tests/js/locale_test.js',

      // Ignore deliberately malformed YAML files.
      'modules/system/tests/fixtures/HtaccessTest/access_test.yml',
      'modules/system/tests/themes/test_theme_libraries_empty/test_theme_libraries_empty.info.yml',
      'tests/Drupal/Tests/Core/Asset/library_test_files/empty.libraries.yml',
      'tests/Drupal/Tests/Core/Asset/library_test_files/invalid_file.libraries.yml',
      'tests/Drupal/Tests/Composer/Plugin/Scaffold/fixtures/drupal-assets-fixture/assets/default.services.yml',
      'tests/Drupal/Tests/Composer/Plugin/Scaffold/fixtures/drupal-profile/assets/profile.default.services.yml',
      'modules/system/tests/themes/sdc_theme_test/components/bar/bar.component.yml',
    ],
  },
  {
    files: ['**/*.{js,mjs,cjs}'],
    plugins: { 'drupal-jquery': jqueryPlugin },
    rules: {
      'no-restricted-globals': [
        'error',
        { globals: ['jQuery', '$'], checkGlobalObject: true },
      ],
      'drupal-jquery/no-reference': 'error',
    },
  },
  {
    files: jqueryOnlyPaths,
    // These files can have directives for rules that we don't run here.
    linterOptions: { noInlineConfig: true },
  },
  {
    ignores: jqueryOnlyPaths,
    extends: [
      js.configs.recommended,
      importPlugin.flatConfigs.recommended,
      jsdoc.configs['flat/recommended'],
      prettierConfig,
      ymlConfig.configs['flat/recommended'],
      {
        languageOptions: {
          ecmaVersion: 2020,
          globals: {
            ...globals.browser,
            ...globals.node,

            Drupal: 'readonly',
            drupalSettings: 'readonly',
            drupalTranslations: 'readonly',
            _: 'readonly',
            Cookies: 'readonly',
            htmx: 'readonly',
            loadjs: 'readonly',
            Shepherd: 'readonly',
            Sortable: 'readonly',
            once: 'readonly',
            CKEditor5: 'readonly',
            CKEDITOR: 'readonly',
            tabbable: 'readonly',
            transliteration: 'readonly',
            bodyScrollLock: 'readonly',
            FloatingUIDOM: 'readonly',
          },
        },
        rules: {
          'prettier/prettier': 'error',
          'no-unexpected-multiline': ['off'],
          'consistent-return': ['off'],
          'no-underscore-dangle': ['off'],
          'max-nested-callbacks': ['warn', 3],
          'import/no-mutable-exports': ['warn'],
          'no-plusplus': [
            'warn',
            {
              allowForLoopAfterthoughts: true,
            },
          ],
          'no-param-reassign': ['off'],
          'no-prototype-builtins': ['off'],
          'no-unused-vars': ['warn'],
          'operator-linebreak': [
            'error',
            'after',
            {
              overrides: {
                '?': 'ignore',
                ':': 'ignore',
              },
            },
          ],
          'yml/indent': ['error', 2],
        },
        settings: {
          jsdoc: {
            tagNamePreference: {
              returns: 'return',
              property: 'prop',
            },
          },
        },
      },
    ],
  },
  {
    files: [
      'playwright.config.ts',
      'tests/Drupal/Playwright/**/*.ts',
      '{modules,themes}/*/tests/src/Playwright/**/*.ts',
    ],
    languageOptions: { parser: tsParser },
    rules: {
      // TypeScript checks types and resolves imports for these files.
      'no-undef': 'off',
      'import/no-unresolved': 'off',
    },
  },
);
