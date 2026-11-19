import js from '@eslint/js';
import react from 'eslint-plugin-react';
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
        plugins: { '@typescript-eslint': tseslint.plugin, 'react-hooks': reactHooks, react },
        settings: { react: { version: 'detect' } },
        rules: {
            ...reactHooks.configs.recommended.rules,
            ...react.configs.recommended.rules,
            ...react.configs['jsx-runtime'].rules,
            'react/prop-types': 'off', // TypeScript checks props.
            'react/no-unstable-nested-components': ['error', { allowAsProps: true }],
            'react/jsx-no-target-blank': 'error',
            'react/button-has-type': 'error',
            'react/self-closing-comp': 'warn',
            // Apostrophes and quotes in copy are fine in JSX; only characters that break it are flagged.
            'react/no-unescaped-entities': ['error', { forbid: ['>', '}'] }],
            'no-unreachable': 'error',
            'no-constant-condition': ['error', { checkLoops: false }],
            '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
        },
    },
];
