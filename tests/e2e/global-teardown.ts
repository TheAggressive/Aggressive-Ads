import { wp } from './wp-cli';

export default function globalTeardown(): void {
	wp( 'eval', 'require "tests/e2e/reset.php";' );
	wp( 'option', 'delete', 'aggr_dev_mail_capture' );
	wp( 'option', 'delete', 'aggr_dev_last_mail' );
}
