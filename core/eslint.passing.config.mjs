import defaultConfig from './eslint.config.mjs';
import { defineConfig } from 'eslint/config';

// Inherit the jQuery checks from the default configuration.
export default defineConfig(defaultConfig, {
  linterOptions: {
    reportUnusedDisableDirectives: 'off',
  },
  rules: {
    'import/named': 'off',
    'import/namespace': 'off',
    'import/no-unresolved': 'off',
  },
});
