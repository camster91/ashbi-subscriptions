import { openSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { spawn } from 'node:child_process';

const [repoRoot, pidFile, logFile, requestedPluginDir, requestedIntegrationDir] = process.argv.slice(2);
const pluginDir = requestedPluginDir || (repoRoot ? join(repoRoot, 'plugin') : '');
const integrationDir = requestedIntegrationDir || (repoRoot ? join(repoRoot, 'plugin/tests/integration') : '');

if (!repoRoot || !pidFile || !logFile || !pluginDir || !integrationDir) {
	throw new Error('Expected repository root, PID file, log file, plugin directory, and integration directory.');
}

const logHandle = openSync(logFile, 'a');
const child = spawn(
	process.execPath,
	[
		join(repoRoot, 'node_modules/@wp-playground/cli/wp-playground.js'),
		'server',
		'--port',
		'8888',
		'--php',
		'8.2',
		'--blueprint',
		join(repoRoot, 'scripts/playground-blueprint.json'),
		'--login',
		'--workers=1',
		'--mount-dir',
		pluginDir,
		'/wordpress/wp-content/plugins/plugin',
		'--mount-dir',
		integrationDir,
		'/ashbi-integration',
		'--mount-dir',
		join(repoRoot, 'scripts/playground-mu-plugins'),
		'/wordpress/wp-content/mu-plugins',
	],
	{
		detached: true,
		stdio: ['ignore', logHandle, logHandle],
	}
);

child.unref();
writeFileSync(pidFile, `${child.pid}\n`);
