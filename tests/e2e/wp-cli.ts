import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

const pluginDir = 'laao-advertiser-portal';

export function wp( ...args: string[] ): string {
	return execFileSync(
		'pnpm',
		[
			'wp-env',
			'run',
			'cli',
			`--env-cwd=wp-content/plugins/${ pluginDir }`,
			'wp',
			...args,
		],
		{
			cwd: resolve( import.meta.dirname, '../..' ),
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'inherit' ],
		}
	);
}
