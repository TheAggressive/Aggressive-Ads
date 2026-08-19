import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.AGGR_E2E_BASE_URL ?? 'http://localhost:9960';
const outputDir = process.env.AGGR_E2E_OUTPUT_DIR ?? '.playwright-results';

/**
 * Whether to leave WebKit out of this run.
 *
 * Set only by the pull-request lane in CI. Unset everywhere else, so a local
 * run and a master run both exercise Safari.
 */
const skipWebKit = 'true' === process.env.AGGR_E2E_SKIP_WEBKIT;

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
			testIgnore: [ /compatibility\//, /reflow\// ],
		},
		// WebKit is opt-out rather than opt-in, so a local run and a master run
		// both get it and only the pull-request lane gives it up. Installing it
		// pulls in GStreamer and the rest of the multimedia stack — the packages
		// that were still downloading when this step timed out at twelve
		// minutes — while every Chromium project needs a fraction of that.
		//
		// The cost is real and bounded: a WebKit-only regression in the shared
		// dialog is caught at merge rather than in review. Still before release,
		// but after approval.
		...( skipWebKit
			? []
			: [
					{
						name: 'webkit-dialog',
						use: { ...devices[ 'Desktop Safari' ] },
						testMatch: /compatibility\/.*\.spec\.ts/,
					},
			  ] ),
		{
			name: 'chromium-reflow',
			use: {
				...devices[ 'Desktop Chrome' ],
				viewport: { width: 320, height: 800 },
			},
			testMatch: /reflow\/.*\.spec\.ts/,
		},
	],
	outputDir,
} );
