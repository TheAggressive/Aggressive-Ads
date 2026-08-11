import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.LAAO_ADS_E2E_BASE_URL ?? 'http://localhost:9960';

export default defineConfig( {
	testDir: './tests/e2e',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	timeout: 60_000,
	expect: {
		timeout: 10_000,
	},
	forbidOnly: Boolean( process.env.CI ),
	globalSetup: './tests/e2e/global-setup.ts',
	globalTeardown: './tests/e2e/global-teardown.ts',
	reporter: [ [ 'list' ], [ './tests/e2e/no-skipped-reporter.ts' ] ],
	use: {
		baseURL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
	outputDir: 'test-results/playwright',
} );
