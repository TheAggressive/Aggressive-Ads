import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

const root = resolve( import.meta.dirname, '../..' );

/**
 * The WordPress root to run against, set by bin/local/studio-e2e.sh.
 *
 * Present means Studio, absent means the Compose stack. Not to be confused with
 * AGGR_STUDIO_PATH, which is that script's own input.
 */
const wpPath = process.env.AGGR_E2E_WP_PATH;

export function wp( ...args: string[] ): string {
	// `--path` leads, so a subcommand's own positional arguments never sit
	// between the global flag and the command it applies to.
	const command = wpPath ? 'studio' : 'bash';
	const commandArgs = wpPath
		? [ 'wp', '--path', wpPath, ...args ]
		: [ 'bin/ci/environment.sh', 'wp', ...args ];

	return execFileSync( command, commandArgs, {
		cwd: root,
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'inherit' ],
	} );
}

export function wpPluginFile( relativePath: string ): string {
	return wp(
		'eval',
		`require WP_PLUGIN_DIR . "/aggressive-ads/${ relativePath }";`
	);
}
