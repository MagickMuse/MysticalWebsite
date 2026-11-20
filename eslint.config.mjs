// JavaScript checks for the site's scripts (plain browser scripts, no build step). Run: npx eslint
import js from '@eslint/js';
import globals from 'globals';

export default [
  { ignores: ['manuscript/assets/astronomy.min.js', 'manuscript/**/index.html', 'node_modules/**'] },
  js.configs.recommended,
  {
    files: ['*.js', 'manuscript/assets/*.js'],
    languageOptions: {
      ecmaVersion: 2020,
      sourceType: 'script',
      globals: { ...globals.browser, Astronomy: 'readonly', Stripe: 'readonly', gtag: 'readonly' },
    },
    rules: {
      // "try { localStorage… } catch (e) {}": storage can be blocked (private browsing); carrying on is the right thing.
      'no-empty': ['error', { allowEmptyCatch: true }],
      'no-unused-vars': ['error', { caughtErrors: 'none' }],
    },
  },
];
