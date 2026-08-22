import { wp, wpPluginFile } from './wp-cli';

export default function globalSetup(): void {
	if ( 'true' === process.env.AGGR_E2E_ARTIFACT ) {
		return;
	}

	wp( 'plugin', 'activate', 'aggressive-ads' );
	wpPluginFile( 'bin/dev/seed.php' );
	wpPluginFile( 'tests/e2e/reset.php' );
	wp( 'option', 'update', 'aggr_dev_mail_capture', '1' );
	wp( 'option', 'delete', 'aggr_dev_last_mail' );
	wpPluginFile( 'tests/e2e/seed-mappings.php' );
	wpPluginFile( 'tests/e2e/seed-users.php' );
	wp( 'theme', 'activate', 'twentytwentyfive' );
	// A fresh WordPress has an empty permalink_structure. Activation hard-flushes
	// plugin rules but does not change that setting, so Apache still has no
	// catch-all to index.php. --hard writes the structure Apache actually uses.
	wp( 'rewrite', 'structure', '/%postname%/', '--hard' );
}
