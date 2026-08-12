import js from '@eslint/js';
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
				Element: 'readonly',
				KeyboardEvent: 'readonly',
				TransitionEvent: 'readonly',
				setTimeout: 'readonly',
				clearTimeout: 'readonly',
				requestAnimationFrame: 'readonly',
				console: 'readonly',
			},
		},
		plugins: {
			'@typescript-eslint': tsPlugin,
		},
		rules: {
			...tsPlugin.configs.recommended.rules,
			'no-unused-vars': 'off',
			'@typescript-eslint/no-unused-vars': [
				'error',
				{ argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
			],
			'@typescript-eslint/consistent-type-imports': 'error',
		},
	},
	{
		files: [ 'src/**/__tests__/**/*.{ts,tsx}', 'src/**/*.{test,spec}.{ts,tsx}' ],
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
