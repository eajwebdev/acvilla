import js from '@eslint/js';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default [
    {
        ignores: ['bootstrap/cache/**', 'public/build/**', 'storage/**', 'vendor/**'],
    },
    {
        files: ['*.js'],
        ...js.configs.recommended,
        languageOptions: {
            ...js.configs.recommended.languageOptions,
            globals: globals.node,
        },
    },
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        languageOptions: {
            parser: tseslint.parser,
            parserOptions: { ecmaVersion: 'latest', sourceType: 'module', ecmaFeatures: { jsx: true } },
            globals: { ...globals.browser, ...globals.es2021 },
        },
        plugins: { '@typescript-eslint': tseslint.plugin, 'react-hooks': reactHooks },
        rules: {
            ...reactHooks.configs.recommended.rules,
            'no-unreachable': 'error',
            'no-constant-condition': ['error', { checkLoops: false }],
            '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
        },
    },
];
