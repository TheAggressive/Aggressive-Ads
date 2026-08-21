import { wp, wpPluginFile } from './wp-cli';

export default function globalTeardown(): void {
	if ( 'true' === process.env.AGGR_E2E_ARTIFACT ) {
		return;
	}

	wpPluginFile( 'tests/e2e/reset.php' );
	wp( 'option', 'delete', 'aggr_dev_mail_capture' );
	wp( 'option', 'delete', 'aggr_dev_last_mail' );
}
