import { wp } from './wp-cli';

export default function globalTeardown(): void {
	wp( 'eval', 'require "tests/e2e/reset.php";' );
}
