"""Offline command/PHP fixtures only: not hosted WordPress runtime evidence."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


def load(name):
    spec = importlib.util.spec_from_file_location(name.replace('-', '_'), ROOT / 'scripts' / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class CommunityRuntimeTests(unittest.TestCase):
    def test_community_environment_installs_canonical_dependencies_and_same_zip(self):
        module = load('validate-published-release')
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp)
            env = module.prepare_environment(output, ROOT, profile='community')
            config = json.loads(env['config'].read_text())
            self.assertEqual(config['plugins'], [])
            calls = []
            def invoke(command, **kwargs):
                calls.append((command, kwargs))
                if 'verify-installed.php' in ' '.join(command):
                    (output / 'installed-package.json').write_text('{"offline-fixture":true}')
                return subprocess.CompletedProcess(command, 0,
                    '{"success":true,"data":{"hpos_mode":"on","hpos_enabled":true}}', '')
            module.run_environment(env, output, 'on', 'runtime', invoke, profile='community')
            commands = '\n'.join(' '.join(command) for command, _ in calls)
            self.assertIn('plugin install woocommerce --activate', commands)
            self.assertIn('plugin install woocommerce-gateway-stripe --activate', commands)
            self.assertIn('/var/www/html/ashbi-release/candidate.zip --activate', commands)
            self.assertIn('is-active ashbi-subscriptions', commands)
            self.assertNotIn('verify-update-isolation.php', commands)
            self.assertNotIn('subscription/subscription.php', commands)
            self.assertFalse(env['owned'].exists())
            self.assertTrue((output / 'installed-package.before.json').exists())
            self.assertTrue((output / 'installed-package.after.json').exists())
            self.assertTrue((output / 'integration.stdout').exists())
            self.assertTrue((output / 'integration.stderr').exists())
            integration = [kwargs for command, kwargs in calls if command[-1].endswith('run-integration.sh')][0]
            self.assertEqual(integration['env']['ASHBI_INTEGRATION_PLUGIN_MAIN'], 'ashbi-subscriptions.php')

    def run_integrity_case(self, mutation=None, primary=None, cleanup_error=None):
        from unittest.mock import patch
        module = load('validate-community-package')
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            bundle = root / 'bundle'
            bundle.mkdir()
            (bundle / 'community-candidate.zip').write_bytes(b'offline immutable candidate')
            repo = root / 'source-fixture'
            for directory in ('plugin/tests/integration', 'scripts/package-validation', 'scripts/playground-mu-plugins'):
                shutil.copytree(ROOT / directory, repo / directory)
            for filename in ('run-integration.sh', 'wp-env-compat.cjs'):
                shutil.copyfile(ROOT / 'scripts' / filename, repo / 'scripts' / filename)
            output = root / 'reports'
            manifest = {'source_commit': 'offline', 'archive_sha256': module.digest(bundle / 'community-candidate.zip'),
                        'canonical_archive_sha256': 'offline', 'members_sha256': {'offline': 'hash'}}
            original_cleanup = module.release.cleanup_environment
            def run(env, *args, **kwargs):
                self.assertTrue(kwargs.get('defer_cleanup'), 'Wrapper must retain executed copies until integrity readback')
                if mutation:
                    mutation(env, output)
                if primary:
                    raise primary
            def cleanup(*args, **kwargs):
                # Integrity must already be durable before removal or a timeout.
                self.assertTrue((output / 'harness-integrity.json').exists())
                if cleanup_error:
                    raise cleanup_error
                return original_cleanup(*args, invoke=lambda *a, **k: None, **kwargs)
            caught = None
            with patch.object(module, 'REPO', repo), \
                 patch.object(module, 'validate_candidate', return_value=manifest), \
                 patch.object(module.release, 'preflight'), \
                 patch.object(module.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, '', '')), \
                 patch.object(module.release, 'run_environment', side_effect=run), \
                 patch.object(module.release, 'cleanup_environment', side_effect=cleanup), \
                 patch('sys.argv', ['validator', 'run', '--bundle', str(bundle), '--output', str(output)]):
                try:
                    module.main()
                except Exception as error:
                    caught = error
            evidence = json.loads((output / 'harness-integrity.json').read_text()) if (output / 'harness-integrity.json').exists() else None
            # Failed cleanup deliberately retains only this test's owned fixture.
            for owned in root.glob('.ashbi-community-owned-*'):
                shutil.rmtree(owned)
            return caught, evidence

    def test_executed_mu_mutation_and_removal_fail_closed_before_cleanup(self):
        for filename in ('safety.php', 'ashbi-integration-loader.php'):
            for remove in (False, True):
                with self.subTest(filename=filename, remove=remove):
                    def mutate(env, output):
                        path = env['owned'] / 'mu' / filename
                        path.unlink() if remove else path.write_text('<?php // disabled offline fixture')
                    error, evidence = self.run_integrity_case(mutate)
                    self.assertIsNotNone(error)
                    self.assertIsNotNone(evidence)
                    self.assertFalse(evidence['fixture_and_candidate_unchanged'])
                    self.assertIn(filename, str(evidence['mismatches']))
                    for name, checksum in evidence['before'].items():
                        if not name.startswith(('input/', 'executed-mu/')):
                            self.assertEqual(evidence['after'][name], checksum, 'Repro must leave source harness unchanged')

    def test_mutable_trusted_inputs_fail_closed(self):
        for filename in ('candidate.zip', 'members.json', 'provenance.json', 'candidate.manifest.json'):
            with self.subTest(filename=filename):
                error, evidence = self.run_integrity_case(lambda env, output: (output / filename).write_bytes(b'forged'))
                self.assertIsNotNone(error)
                self.assertIsNotNone(evidence)
                self.assertFalse(evidence['fixture_and_candidate_unchanged'])
                self.assertIn(filename, str(evidence['mismatches']))

    def test_source_fixture_helper_and_added_mu_are_checked(self):
        for target in ('ashbi-integration', 'ashbi-tools', 'added-mu'):
            with self.subTest(target=target):
                def mutate(env, output):
                    mappings = json.loads(env['config'].read_text())['mappings']
                    if target == 'added-mu':
                        path = env['owned'] / 'mu/extra.php'
                    else:
                        filename = 'security-boundaries.php' if target == 'ashbi-integration' else 'verify-installed.php'
                        path = Path(mappings[target]) / filename
                    path.write_text('<?php // forged offline helper')
                    # Forging the public hash report cannot change host-held expectations.
                    (output / 'provenance.json').write_text('{"fixture_sha256":{}}')
                    (output / 'harness-integrity.json').write_text('{"fixture_and_candidate_unchanged":true}')
                error, evidence = self.run_integrity_case(mutate)
                self.assertIsNotNone(error)
                self.assertFalse(evidence['fixture_and_candidate_unchanged'])
                self.assertGreaterEqual(len(evidence['mismatches']), 2)

    def test_unmodified_integrity_survives_successful_cleanup(self):
        error, evidence = self.run_integrity_case()
        self.assertIsNone(error)
        self.assertTrue(evidence['fixture_and_candidate_unchanged'])
        self.assertEqual(evidence['errors'], [])

    def test_real_runner_defers_cleanup_on_primary_error(self):
        module = load('validate-published-release')
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp) / 'reports'
            output.mkdir()
            env = module.prepare_environment(output, ROOT, profile='community')
            calls = []
            def invoke(command, **kwargs):
                calls.append(command)
                raise RuntimeError('offline startup failed')
            try:
                with self.assertRaisesRegex(RuntimeError, 'offline startup failed'):
                    module.run_environment(env, output, 'off', 'runtime', invoke,
                                           profile='community', defer_cleanup=True)
                self.assertFalse(any('cleanup' in command for command in calls))
                self.assertTrue((env['owned'] / 'mu/safety.php').exists())
                module.cleanup_environment(output, lambda *a, **k: None, profile='community')
            finally:
                if env['owned'].exists():
                    shutil.rmtree(env['owned'])

    def test_locked_builder_exposes_mu_but_not_community_ownership(self):
        module = load('validate-published-release')
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp) / 'reports'
            output.mkdir()
            env = module.prepare_environment(output, ROOT, profile='community')
            try:
                javascript = r'''
const path = require('node:path');
const root = path.dirname(require.resolve('@wordpress/env/package.json'));
const build = require(path.join(root, 'lib/runtime/docker/build-docker-compose-config.js'));
const mappings = Object.fromEntries(Object.entries(JSON.parse(process.argv[1])).map(([key, value]) => [key, {path: value}]));
const compose = build({testsEnvironment:false,workDirectoryPath:process.argv[2],env:{development:{mappings,pluginSources:[],themeSources:[],port:8888},tests:{}}});
console.log(JSON.stringify(compose.services.cli.volumes));
'''
                result = subprocess.run([shutil.which('node'), '-e', javascript,
                                         json.dumps(json.loads(env['config'].read_text())['mappings']),
                                         (env['owned'] / 'cache').as_posix()],
                                        cwd=ROOT, capture_output=True, text=True, check=True)
                mounts = json.loads(result.stdout)
                self.assertIn((env['owned'] / 'mu').as_posix() + ':/var/www/html/wp-content/mu-plugins', mounts)
                self.assertIn(output.as_posix() + ':/var/www/html/ashbi-release', mounts)
                self.assertFalse(any(mount.endswith(':ro') for mount in mounts))
                for mount in mounts:
                    source = Path(mount.rsplit(':/', 1)[0])
                    self.assertFalse(env['config'].is_relative_to(source))
                    self.assertFalse((env['owned'] / '.ashbi-owned.json').is_relative_to(source))
                module.cleanup_environment(output, lambda *a, **k: None, profile='community')
            finally:
                if env['owned'].exists():
                    shutil.rmtree(env['owned'])

    def test_mutation_and_cleanup_failure_preserve_both_errors(self):
        error, evidence = self.run_integrity_case(
            lambda env, output: (env['owned'] / 'mu/safety.php').unlink(),
            cleanup_error=subprocess.TimeoutExpired('offline cleanup', 180))
        self.assertIsNotNone(evidence)
        self.assertFalse(evidence['fixture_and_candidate_unchanged'])
        self.assertIn('integrity', str(error).lower())
        self.assertIn('cleanup', str(error).lower())
        self.assertIn('TimeoutExpired', str(evidence['errors']))

    def test_cleanup_failure_keeps_evidence_and_is_retryable_with_same_owner(self):
        module = load('validate-published-release')
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp) / 'reports'
            output.mkdir()
            env = module.prepare_environment(output, ROOT, profile='community')
            projects = []
            def fail(command, **kwargs):
                projects.append(kwargs['env']['COMPOSE_PROJECT_NAME'])
                raise subprocess.TimeoutExpired(command, 180)
            try:
                with self.assertRaises(subprocess.TimeoutExpired):
                    module.cleanup_environment(output, fail, profile='community')
                self.assertTrue(env['owned'].exists())
                def retry(command, **kwargs):
                    projects.append(kwargs['env']['COMPOSE_PROJECT_NAME'])
                module.cleanup_environment(output, retry, profile='community')
                self.assertEqual(projects, [env['environ']['COMPOSE_PROJECT_NAME']] * 2)
                self.assertFalse(env['owned'].exists())
                module.cleanup_environment(output, lambda *a, **k: self.fail('Already removed'), profile='community')
            finally:
                if env['owned'].exists():
                    shutil.rmtree(env['owned'])
        error, evidence = self.run_integrity_case(cleanup_error=RuntimeError('offline cleanup-only failure'))
        self.assertIsNotNone(error)
        self.assertTrue(evidence['fixture_and_candidate_unchanged'])
        self.assertEqual(evidence['errors'][0]['phase'], 'cleanup')

    def test_primary_and_cleanup_failure_preserve_both_errors(self):
        error, evidence = self.run_integrity_case(primary=RuntimeError('offline primary validation failure'),
                                                cleanup_error=RuntimeError('offline cleanup failure'))
        self.assertIsNotNone(evidence)
        self.assertTrue(evidence['fixture_and_candidate_unchanged'])
        self.assertIn('offline primary validation failure', str(error))
        self.assertIn('offline cleanup failure', str(error))
        self.assertEqual(len(evidence['errors']), 2)

    def test_community_ownership_is_not_under_writable_report_mount(self):
        module = load('validate-published-release')
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp) / 'reports'
            output.mkdir()
            env = module.prepare_environment(output, ROOT, profile='community')
            try:
                config = json.loads(env['config'].read_text())
                self.assertFalse(env['owned'].is_relative_to(output))
                for source in config['mappings'].values():
                    self.assertFalse(env['config'].is_relative_to(Path(source)))
                    self.assertFalse((env['owned'] / '.ashbi-owned.json').is_relative_to(Path(source)))
                    self.assertFalse((env['owned'] / 'cache').is_relative_to(Path(source)))
                # Container report spoofing cannot redirect ownership/configuration.
                forged = output / 'owned-environment'
                forged.mkdir()
                (forged / '.ashbi-owned.json').write_text('{"id":"foreign"}')
                self.assertEqual(module.environment_details(output, profile='community')['owned'], env['owned'])
                module.cleanup_environment(output, lambda *a, **k: None, profile='community')
                self.assertTrue(forged.exists())
            finally:
                if env['owned'].exists():
                    shutil.rmtree(env['owned'])

    def test_community_installed_headers_and_bytes_are_checked(self):
        checker = ROOT / 'scripts/package-validation/verify-installed.php'
        harness = r'''<?php
namespace Automattic\WooCommerce\Utilities {
class OrderUtil { static function custom_orders_table_usage_is_enabled() { return false; } }
}
namespace {
class WP_CLI { static function error($m) { throw new \RuntimeException($m); } static function success($m) {} }
function wp_get_environment_type() { return 'local'; }
function plugin_basename($f) { return 'ashbi-subscriptions/' . basename($f); }
function is_plugin_active($n) { return $n === 'ashbi-subscriptions/ashbi-subscriptions.php'; }
function get_bloginfo($f) { return 'offline-fixture'; }
function wp_json_encode($v,$f=0) { return json_encode($v,$f); }
function get_plugin_data($f,$m,$t) { return ['TextDomain'=>$GLOBALS['scenario']==='domain'?'subscription':'ashbi-subscriptions', 'UpdateURI'=>$GLOBALS['scenario']==='uri'?'false':'', 'RequiresPlugins'=>$GLOBALS['scenario']==='dependency'?'wrong-slug':'woocommerce']; }
define('WP_PLUGIN_DIR', $argv[1] . '/plugins');
define('SUBSCRPT_FILE', WP_PLUGIN_DIR . '/ashbi-subscriptions/ashbi-subscriptions.php');
define('WC_VERSION', 'offline-fixture');
$GLOBALS['scenario']=$argv[3];
$args=[$argv[1]]; require $argv[2];
}
'''
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp)
            plugin = output / 'plugins/ashbi-subscriptions'
            plugin.mkdir(parents=True)
            main = plugin / 'ashbi-subscriptions.php'
            main.write_text('<?php // offline fixture without Update URI')
            (output / 'members.json').write_text(json.dumps({'ashbi-subscriptions/ashbi-subscriptions.php':hashlib.sha256(main.read_bytes()).hexdigest()}))
            (output / 'provenance.json').write_text(json.dumps({'hpos':'off','distribution':'community-directory-candidate'}))
            entry = output / 'fixture.php'
            entry.write_text(harness)
            for scenario in ('valid', 'write-error', 'uri', 'domain', 'dependency', 'empty-uri', 'bytes'):
                if scenario == 'write-error':
                    (output / 'installed-package.json').unlink()
                    (output / 'installed-package.json').mkdir()
                elif (output / 'installed-package.json').is_dir():
                    (output / 'installed-package.json').rmdir()
                if scenario == 'empty-uri':
                    main.write_text('<?php\n/**\n * Update URI: \n */')
                    (output / 'members.json').write_text(json.dumps({'ashbi-subscriptions/ashbi-subscriptions.php':hashlib.sha256(main.read_bytes()).hexdigest()}))
                if scenario == 'bytes':
                    main.write_text('changed fixture')
                result = subprocess.run(['php', str(entry), str(output), str(checker), scenario], capture_output=True, text=True)
                if scenario == 'valid':
                    self.assertEqual(result.returncode, 0, result.stderr)
                    report = json.loads((output / 'installed-package.json').read_text())
                    self.assertEqual(report['text_domain'], 'ashbi-subscriptions')
                    self.assertFalse(report['update_uri_header_present'])
                else:
                    self.assertNotEqual(result.returncode, 0, scenario)

    def test_candidate_artifact_contract_rejects_foreign_bytes_and_provenance(self):
        from unittest.mock import patch
        module = load('validate-community-package')
        import zipfile
        with tempfile.TemporaryDirectory() as temp:
            bundle = Path(temp)
            canonical = {'subscription/subscription.php':b'canonical offline fixture'}
            community = {'ashbi-subscriptions/ashbi-subscriptions.php':b'community offline fixture'}
            # Full count is fabricated for boundary tests only; real transform is producer/consumer enforced.
            for index in range(210):
                community[f'ashbi-subscriptions/fixture-{index}.txt'] = b'offline'
            for name, members in [('github-compatibility.zip', canonical), ('community-candidate.zip', community)]:
                with zipfile.ZipFile(bundle / name, 'w') as archive:
                    for member, data in members.items():
                        archive.writestr(member, data)
            hashes = lambda members: {n:hashlib.sha256(d).hexdigest() for n,d in members.items()}
            evidence = {'distribution':'community-directory-candidate','source_commit':'offline-commit',
                        'dirty_tree':False, 'archive_sha256':hashlib.sha256((bundle/'community-candidate.zip').read_bytes()).hexdigest(),
                        'canonical_archive_sha256':hashlib.sha256((bundle/'github-compatibility.zip').read_bytes()).hexdigest(),
                        'members_sha256':hashes(community),'canonical_members_sha256':hashes(canonical),
                        'transform_scripts_sha256':module.transform_hashes()}
            manifest = bundle / 'community-candidate.manifest.json'
            manifest.write_text(json.dumps(evidence))
            with patch.object(module, 'source_commit', return_value='offline-commit'), \
                 patch.object(module.builder, 'validate_canonical'), \
                 patch.object(module.builder, 'verify_members') as approved:
                result = module.validate_candidate(bundle)
                self.assertEqual(len(result['members_sha256']), 211)
                approved.assert_called_once_with(canonical, community)
                for key, value in [('dirty_tree', True), ('source_commit', 'foreign'),
                                   ('archive_sha256', '0'*64), ('transform_scripts_sha256', {})]:
                    with self.subTest(key=key):
                        manifest.write_text(json.dumps(dict(evidence, **{key:value})))
                        with self.assertRaises(ValueError):
                            module.validate_candidate(bundle)
                manifest.write_text(json.dumps(evidence))
                (bundle/'community-candidate.zip').write_bytes(b'changed')
                with self.assertRaises(ValueError):
                    module.validate_candidate(bundle)

    def test_workflow_builds_once_and_consumes_identical_artifact(self):
        workflow = (ROOT / '.github/workflows/community-package.yml').read_text()
        self.assertEqual(workflow.count('build-community-package.py build '), 1)
        self.assertIn('artifact-id: ${{ steps.candidate.outputs.artifact-id }}', workflow)
        self.assertEqual(workflow.count('artifact-ids: ${{ needs.community-package.outputs.artifact-id }}'), 2)
        self.assertIn("hpos: ['off', 'on']", workflow)
        self.assertIn('max-parallel: 1', workflow)
        self.assertIn('--kind runtime', workflow)
        self.assertIn('--kind plugin-check', workflow)
        self.assertIn('if: always()', workflow)
        self.assertNotIn('pull_request_target', workflow)
        self.assertNotIn('contents: write', workflow)
        self.assertIn('nonblocking', workflow)
        self.assertEqual(workflow.count('set -o pipefail'), 2, 'Outer tee must not hide validation failures')

    def test_community_command_failures_preserve_raw_output_and_owned_cleanup(self):
        module = load('validate-published-release')
        for failure in ('start', 'install', 'integration', 'timeout', 'integration-timeout'):
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as temp:
                output = Path(temp)
                env = module.prepare_environment(output, ROOT, profile='community')
                calls = []
                def invoke(command, **kwargs):
                    calls.append(command)
                    if 'verify-installed.php' in ' '.join(command):
                        (output / 'installed-package.json').write_text('{"offline-fixture":true}')
                    if ((failure == 'timeout' and 'start' in command)
                            or (failure == 'integration-timeout' and command[-1].endswith('run-integration.sh'))):
                        raise subprocess.TimeoutExpired(command, 600, output=b'offline raw stdout', stderr=b'offline raw stderr')
                    if ((failure == 'start' and 'start' in command)
                            or (failure == 'install' and 'install' in command)
                            or (failure == 'integration' and command[-1].endswith('run-integration.sh'))):
                        raise subprocess.CalledProcessError(7, command, output='offline raw stdout', stderr='offline raw stderr')
                    return subprocess.CompletedProcess(command, 0, '', '')
                with self.assertRaises(subprocess.TimeoutExpired if 'timeout' in failure else subprocess.CalledProcessError):
                    module.run_environment(env, output, 'off', 'runtime', invoke, profile='community')
                self.assertTrue(any('cleanup' in c for c in calls))
                self.assertFalse(env['owned'].exists())
                prefix = 'integration' if failure.startswith('integration') else 'wp-env'
                self.assertIn('offline raw stdout', (output / (prefix + '.stdout')).read_text())
                self.assertIn('offline raw stderr', (output / (prefix + '.stderr')).read_text())

    def test_community_plugin_check_preserves_every_finding_with_early_bootstrap(self):
        module = load('validate-published-release')
        findings = [{'file':'offline.php','type':'ERROR','code':'offline_error'},
                    {'file':'offline.php','type':'WARNING','code':'offline_warning'},
                    {'file':'offline.php','type':'OTHER','code':'offline_unknown'}]
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp)
            env = module.prepare_environment(output, ROOT, profile='community')
            calls = []
            def invoke(command, **kwargs):
                calls.append(command)
                text = ' '.join(command)
                if 'verify-installed.php' in text:
                    (output / 'installed-package.json').write_text('{"offline-fixture":true}')
                if 'plugin check ' in text:
                    (output / 'plugin-check.stdout').write_text(json.dumps(findings))
                    (output / 'plugin-check.stderr').write_text('offline raw PCP warning')
                    (output / 'plugin-check.exit-code').write_text('0')
                return subprocess.CompletedProcess(command, 0, '', '')
            module.run_environment(env, output, 'off', 'plugin-check', invoke, profile='community')
            command = next(' '.join(c) for c in calls if 'plugin check ' in ' '.join(c))
            self.assertIn('plugin check ashbi-subscriptions/ashbi-subscriptions.php', command)
            self.assertIn('--require=./wp-content/plugins/plugin-check/cli.php', command)
            self.assertIn('--format=strict-json --fields=file,line,column,type,code,message,docs', command)
            self.assertNotIn('--exclude', command)
            self.assertEqual(json.loads((output/'plugin-check.json').read_text()), findings)
            self.assertEqual((output/'plugin-check.policy-exit-code').read_text(), '1')
            self.assertEqual((output/'plugin-check.stderr').read_text(), 'offline raw PCP warning')
            self.assertFalse(env['owned'].exists())

    def test_community_missing_installed_readback_fails_closed(self):
        module = load('validate-published-release')
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp)
            env = module.prepare_environment(output, ROOT, profile='community')
            def invoke(command, **kwargs):
                return subprocess.CompletedProcess(command, 0,
                    '{"success":true,"data":{"hpos_mode":"off","hpos_enabled":false}}', '')
            with self.assertRaises(FileNotFoundError):
                module.run_environment(env, output, 'off', 'runtime', invoke, profile='community')
            self.assertFalse(env['owned'].exists())

    def test_runner_uses_only_static_root_main_pairs(self):
        fake = r'''curl() {
case "$*" in
 *security-boundaries*) printf '{"success":true}\n200';;
 *wp-json/wp/v2/plugins*) printf '[{"plugin":"%s\\/%s","status":"active"},{"plugin":"woocommerce\\/woocommerce","status":"active"},{"plugin":"woocommerce-gateway-stripe\\/woocommerce-gateway-stripe","status":"active"}]' "$ASHBI_INTEGRATION_PLUGIN_DIR" "${ASHBI_INTEGRATION_PLUGIN_MAIN%.php}";;
 *wp-admin/index.php*) printf 'wpApiSettings = {"nonce":"offline-fixture"}';;
esac
}; sleep() { :; }; export -f curl sleep
bash "$1"
'''
        for root, main, status in [('plugin', 'subscription.php', 0), ('subscription', 'subscription.php', 0),
                                   ('ashbi-subscriptions', 'ashbi-subscriptions.php', 0),
                                   ('subscription', 'ashbi-subscriptions.php', 2),
                                   ('ashbi-subscriptions', 'subscription.php', 2),
                                   ('ashbi-subscriptions', '../evil.php', 2),
                                   ('ashbi-subscriptions;evil', 'ashbi-subscriptions.php', 2)]:
            with self.subTest(root=root, main=main):
                result = subprocess.run([shutil.which('bash'), '-c', fake, 'fixture',
                                         (ROOT / 'scripts/run-integration.sh').as_posix()],
                                        env=dict(os.environ, ASHBI_INTEGRATION_PLUGIN_DIR=root,
                                                 ASHBI_INTEGRATION_PLUGIN_MAIN=main),
                                        capture_output=True, text=True, timeout=8)
                self.assertEqual(result.returncode, status, result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
