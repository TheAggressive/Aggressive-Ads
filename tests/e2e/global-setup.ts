import { wp } from './wp-cli';

export default function globalSetup(): void {
	wp( 'plugin', 'activate', 'aggressive-ads' );
	wp( 'eval', 'require "bin/dev/seed.php";' );
	wp( 'eval', 'require "tests/e2e/reset.php";' );
	wp( 'option', 'update', 'aggr_dev_mail_capture', '1' );
	wp( 'option', 'delete', 'aggr_dev_last_mail' );
	wp( 'eval', 'require "tests/e2e/seed-mappings.php";' );
	wp( 'user', 'update', 'advertiser', '--user_pass=advertiser' );
	wp( 'user', 'update', 'admin', '--user_pass=admin' );
	wp( 'theme', 'activate', 'twentytwentyfive' );
	// A fresh wp-env has an empty permalink_structure. Activation hard-flushes
	// plugin rules but does not change that setting, so Apache still has no
	// catch-all to index.php. --hard writes the structure Apache actually uses.
	wp( 'rewrite', 'structure', '/%postname%/', '--hard' );
}
