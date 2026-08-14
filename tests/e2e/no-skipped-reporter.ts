import type {
	FullResult,
	Reporter,
	TestCase,
	TestResult,
} from '@playwright/test/reporter';

export default class NoSkippedReporter implements Reporter {
	private readonly skipped: string[] = [];

	onTestEnd( test: TestCase, result: TestResult ): void {
		if ( result.status === 'skipped' ) {
			this.skipped.push( test.titlePath().join( ' › ' ) );
		}
	}

	onEnd( result: FullResult ): FullResult {
		if ( this.skipped.length === 0 ) {
			return result;
		}

		for ( const title of this.skipped ) {
			console.error( `Skipped browser test: ${ title }` );
		}

		return { ...result, status: 'failed' };
	}
}
