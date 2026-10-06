'use strict';

// wp-env 11 still calls require('simple-git') directly; v4 exposes a named
// factory instead. Restore that export shape without altering v4's security
// guards or options. This cache adaptation is limited to this tooling process.
const gitPath = require.resolve('simple-git');
const git = require(gitPath);
if (typeof git !== 'function') {
  const factory = git.simpleGit;
  if (typeof factory !== 'function') {
    throw new TypeError('The locked simple-git dependency has no simpleGit factory');
  }
  require.cache[gitPath].exports = Object.assign(factory, git);
}
module.exports = require(gitPath);
