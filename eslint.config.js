import js from '@eslint/js';
import reactPlugin from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import tsPlugin from '@typescript-eslint/eslint-plugin';
import tsParser from '@typescript-eslint/parser';

export default [
	js.configs.recommended,
	{
		ignores: [
			'*.config.js',
			'*.config.mjs',
			'bin/**',
			'dist/**',
			'build/**',
			'node_modules/**',
			'vendor/**',
			'tests/**',
			'release/**',
			'assets/**',
		],
	},
	{
		files: [ 'src/blocks/**/*.js' ],
		languageOptions: {
			parserOptions: {
				ecmaVersion: 2022,
				sourceType: 'module',
				ecmaFeatures: { jsx: true },
			},
			globals: {
				window: 'readonly',
				document: 'readonly',
				fetch: 'readonly',
				URLSearchParams: 'readonly',
				HTMLElement: 'readonly',
			},
		},
		plugins: {
			react: reactPlugin,
		},
		rules: {
			'react/jsx-uses-vars': 'error',
		},
	},
	{
		files: [ 'src/**/*.{ts,tsx}' ],
		languageOptions: {
			parser: tsParser,
			parserOptions: {
				ecmaVersion: 2022,
				sourceType: 'module',
				project: './tsconfig.json',
			},
			globals: {
				window: 'readonly',
				document: 'readonly',
				history: 'readonly',
				CSS: 'readonly',
				HTMLElement: 'readonly',
				HTMLAnchorElement: 'readonly',
				HTMLFormElement: 'readonly',
				HTMLInputElement: 'readonly',
				Element: 'readonly',
				Event: 'readonly',
				KeyboardEvent: 'readonly',
				TransitionEvent: 'readonly',
				DragEvent: 'readonly',
				File: 'readonly',
				DataTransfer: 'readonly',
				FormData: 'readonly',
				Image: 'readonly',
				URL: 'readonly',
				fetch: 'readonly',
				Headers: 'readonly',
				AbortController: 'readonly',
				setTimeout: 'readonly',
				clearTimeout: 'readonly',
				requestAnimationFrame: 'readonly',
				console: 'readonly',
			},
		},
		plugins: {
			'@typescript-eslint': tsPlugin,
			'react-hooks': reactHooks,
		},
		rules: {
			...tsPlugin.configs.recommended.rules,

			/*
			 * Hook rules, which nothing checked until now.
			 *
			 * Their absence was invisible in the worst way: a
			 * `react-hooks/exhaustive-deps` disable comment sat in this
			 * codebase suppressing a rule that was never configured, so it
			 * read as a considered exception and was a no-op. The bug it was
			 * covering — a memo closing over stale state — was found by
			 * reading, which is not a strategy.
			 *
			 * exhaustive-deps is a warning upstream. `--max-warnings 0` makes
			 * it fail the lane here, because a stale closure is a wrong render,
			 * not a style preference.
			 */
			'react-hooks/rules-of-hooks': 'error',
			'react-hooks/exhaustive-deps': 'error',

			'no-unused-vars': 'off',
			'@typescript-eslint/no-unused-vars': [
				'error',
				{ argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
			],
			'@typescript-eslint/consistent-type-imports': 'error',
		},
	},
	{
		files: [
			'src/**/__tests__/**/*.{ts,tsx}',
			'src/**/*.{test,spec}.{ts,tsx}',
		],
		languageOptions: {
			globals: {
				describe: 'readonly',
				it: 'readonly',
				expect: 'readonly',
				beforeEach: 'readonly',
				afterEach: 'readonly',
				beforeAll: 'readonly',
				afterAll: 'readonly',
				jest: 'readonly',
				test: 'readonly',
			},
		},
	},
];
