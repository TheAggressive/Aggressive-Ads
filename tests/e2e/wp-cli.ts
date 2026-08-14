import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

// Mapped in .wp-env.json so GitHub's Aggressive-Ads checkout appears as this
// slug inside the container.
const pluginDir = 'aggressive-ads';

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
