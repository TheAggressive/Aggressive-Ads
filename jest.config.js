/** @type {import('jest').Config} */
export default {
	rootDir: '.',
	roots: [ '<rootDir>/src' ],
	testMatch: [
		'**/__tests__/**/*.[jt]s?(x)',
		'**/?(*.)+(spec|test).[jt]s?(x)',
	],
	testEnvironment: 'jsdom',
	transform: {
		'^.+\\.(js|jsx|ts|tsx)$':
			'<rootDir>/node_modules/@wordpress/scripts/config/babel-transform.js',
	},
	moduleNameMapper: {
		'\\.(css|scss)$':
			'<rootDir>/node_modules/@wordpress/scripts/config/jest-style-mock.js',
	},
	moduleFileExtensions: [ 'ts', 'tsx', 'js', 'jsx', 'json' ],
};
