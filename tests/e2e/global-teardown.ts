import { wp } from './wp-cli';

export default function globalTeardown(): void {
	wp( 'eval', 'require "tests/e2e/reset.php";' );
	wp( 'option', 'delete', 'laao_ads_dev_mail_capture' );
	wp( 'option', 'delete', 'laao_ads_dev_last_mail' );
}
