'use strict';

require('./wp-env-compat.cjs');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { mkdtempSync, readFileSync, rmSync, writeFileSync } = require('node:fs');
const { join, resolve } = require('node:path');
const { dirname } = require('node:path');
const envRoot = dirname(require.resolve('@wordpress/env/package.json'));
const { downloadGitSource } = require(join(envRoot, 'lib/download-sources.js'));
const yaml = require('js-yaml');

async function main() {
  // Keep all fabricated repositories under the caller's owned scratch directory.
  const scratch = process.env.TMPDIR || require('node:os').tmpdir();
  const root = mkdtempSync(join(resolve(scratch), 'ashbi-tooling-'));
  const source = join(root, 'source');
  const target = join(root, 'clone');
  try {
    execFileSync('git', ['init', '--initial-branch=main', source]);
    writeFileSync(join(source, 'fixture.txt'), 'fabricated tooling fixture');
    execFileSync('git', ['-C', source, 'add', 'fixture.txt']);
    execFileSync('git', ['-C', source, '-c', 'user.name=CI Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-m', 'fixture']);
    const options = { onProgress() {}, spinner: { info() {}, start() {} }, debug: false };
    const specification = { type: 'git', url: source.replace(/\\/g, '/'), clonePath: target, ref: 'main' };
    await downloadGitSource(specification, options);
    assert.equal(readFileSync(join(target, 'fixture.txt'), 'utf8'), 'fabricated tooling fixture');
    writeFileSync(join(source, 'fixture.txt'), 'updated fabricated fixture');
    execFileSync('git', ['-C', source, 'add', 'fixture.txt']);
    execFileSync('git', ['-C', source, '-c', 'user.name=CI Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-m', 'update fixture']);
    await downloadGitSource(specification, options);
    assert.equal(readFileSync(join(target, 'fixture.txt'), 'utf8'), 'updated fabricated fixture');
    const git = require('simple-git');
    assert.equal(typeof git, 'function', 'wp-env requires the legacy callable CommonJS export');
    assert.equal(git.simpleGit, git, 'named export must retain the patched implementation');
    await assert.rejects(git(source).raw(['-c', 'core.sshCommand=echo unsafe', 'status']), /unsafe|sshCommand/i);
    const config = { services: { wordpress: { image: 'wordpress:latest', ports: ['8888:80'], environment: { WORDPRESS_DEBUG: '1' } } } };
    assert.deepEqual(yaml.load(yaml.dump(config)), config);
    console.log('Integration tooling compatibility passed: wp-env local clone/update, patched Git guard, YAML round-trip.');
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
