import defaultConfig from './eslint.config.mjs';
import { defineConfig } from 'eslint/config';

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
