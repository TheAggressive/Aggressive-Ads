import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

const pluginRoot = '/var/www/html/wp-content/plugins/aggressive-ads';

export function wp( ...args: string[] ): string {
	return execFileSync( 'bash', [ 'bin/ci/environment.sh', 'wp', ...args ], {
		cwd: resolve( import.meta.dirname, '../..' ),
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'inherit' ],
	} );
}

export function wpPluginFile( relativePath: string ): string {
	return wp( 'eval', `require "${ pluginRoot }/${ relativePath }";` );
}
