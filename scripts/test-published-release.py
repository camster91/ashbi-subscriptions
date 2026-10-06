"""Offline fabricated ZIP/command fixtures; NOT WordPress runtime evidence."""
import os
import shutil
import subprocess
import hashlib
import importlib.util
import tempfile
import unittest
import zipfile
from pathlib import Path

SCRIPT = Path(__file__).with_name('validate-published-release.py')


class PublishedReleaseTests(unittest.TestCase):
    def load_module(self):
        spec = importlib.util.spec_from_file_location('release_validator', SCRIPT)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def test_wp_env_mappings_use_wordpress_relative_targets(self):
        module = self.load_module()
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp)
            output.mkdir(exist_ok=True)
            env = module.prepare_environment(output, SCRIPT.parent.parent)
            config = __import__('json').loads(env['config'].read_text())
            self.assertTrue(all(not target.startswith('/') for target in config['mappings']),
                            'wp-env prefixes mappings with /var/www/html; root-looking targets are not root mounts')
            calls = []
            def invoke(command, **kwargs):
                calls.append(command)
                return subprocess.CompletedProcess(command, 0, '{"success":true,"data":{"hpos_mode":"off","hpos_enabled":false}}\n', '')
            module.run_environment(env, output, 'off', 'runtime', invoke)
            combined = '\n'.join(' '.join(command) for command in calls)
            self.assertIn('/var/www/html/ashbi-release/candidate.zip', combined)
            self.assertIn('/var/www/html/ashbi-tools/verify-installed.php', combined)
            self.assertNotIn(' /ashbi-release', combined)
            self.assertNotIn(' /ashbi-tools', combined)

    def test_compose_project_is_marker_bound_and_ambient_overrides_are_rejected(self):
        from unittest.mock import patch
        module = self.load_module()
        for key in ('COMPOSE_PROJECT_NAME', 'COMPOSE_ENV_FILES', 'COMPOSE_FILE', 'COMPOSE_PROFILES'):
            with self.subTest(key=key), self.assertRaises(ValueError):
                module.preflight('off', {key: 'existing-user-project'}, lambda: False)
        names = []
        for _ in range(2):
            with tempfile.TemporaryDirectory() as temp:
                output = Path(temp)
                with patch.dict(os.environ, {'COMPOSE_PROJECT_NAME': 'existing-user-project',
                                             'COMPOSE_ENV_FILES': 'unknown.env'}):
                    env = module.prepare_environment(output, SCRIPT.parent.parent)
                    marker = __import__('json').loads((env['owned'] / '.ashbi-owned.json').read_text())
                    project = 'ashbi-published-' + marker['id'].replace('-', '')
                    self.assertEqual(env['environ']['COMPOSE_PROJECT_NAME'], project)
                    self.assertEqual(env['environ']['COMPOSE_DISABLE_ENV_FILE'], '1')
                    self.assertNotIn('COMPOSE_ENV_FILES', env['environ'])
                    calls = []
                    module.cleanup_environment(output, lambda command, **kwargs: calls.append(kwargs))
                    self.assertEqual(calls[0]['env']['COMPOSE_PROJECT_NAME'], project)
                    names.append(project)
        self.assertNotEqual(names[0], names[1])

    def test_real_locked_docker_builder_matches_container_consumers(self):
        module = self.load_module()
        with tempfile.TemporaryDirectory() as temp:
            env = module.prepare_environment(Path(temp), SCRIPT.parent.parent)
            config = __import__('json').loads(env['config'].read_text())
            javascript = r'''
const path = require('node:path');
const root = path.dirname(require.resolve('@wordpress/env/package.json'));
const build = require(path.join(root, 'lib/runtime/docker/build-docker-compose-config.js'));
const mappings = Object.fromEntries(Object.entries(JSON.parse(process.argv[1])).map(([key, value]) => [key, {path: value}]));
const compose = build({testsEnvironment:false,workDirectoryPath:'/offline-cache',env:{development:{mappings,pluginSources:[],themeSources:[],port:8888},tests:{}}});
console.log(JSON.stringify(compose.services.cli.volumes));
'''
            result = subprocess.run([shutil.which('node'), '-e', javascript,
                                     __import__('json').dumps(config['mappings'])],
                                    cwd=SCRIPT.parent.parent, capture_output=True, text=True, check=True)
            mounts = __import__('json').loads(result.stdout)
            destinations = [mount.rsplit(':', 1)[-1] for mount in mounts]
            for name in ('release', 'tools', 'integration'):
                self.assertIn('/var/www/html/ashbi-' + name, destinations)
                self.assertNotIn('/ashbi-' + name, destinations)

    def test_matrix_fixture_keeps_selected_datastore_through_all_checks(self):
        source = (SCRIPT.parent.parent / 'plugin/tests/integration/security-boundaries.php').read_text()
        self.assertNotIn('$previous_hpos_setting', source,
                         'The explicit disposable matrix mode must not be restored midway through lifecycle checks')
        self.assertIn("'hpos_enabled'", source)

    def test_runtime_rejects_success_without_actual_datastore_trace(self):
        module = self.load_module()
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp)
            env = module.prepare_environment(output, SCRIPT.parent.parent)
            def invoke(command, **kwargs):
                return subprocess.CompletedProcess(command, 0, '{"success":true}\n', '')
            with self.assertRaisesRegex(RuntimeError, 'datastore'):
                module.run_environment(env, output, 'off', 'runtime', invoke)

    def test_update_isolation_requires_real_service_evidence_for_other_plugins(self):
        checker = SCRIPT.parent / 'package-validation/verify-update-isolation.php'
        self.assertTrue(checker.exists(), 'Cached update-offer verification is missing')
        harness = r'''<?php
class WP_CLI {
 static function error($message) { throw new RuntimeException($message); }
 static function success($message) { print $message; }
}
define('SUBSCRPT_FILE', __FILE__);
function wp_get_environment_type() { return 'local'; }
function plugin_basename($file) { return 'subscription/subscription.php'; }
function get_plugin_data($file,$markup,$translate) { return array('UpdateURI'=>$GLOBALS['scenario']==='header'?'':'false'); }
function delete_site_transient($name) { return true; }
function wp_update_plugins() { $GLOBALS['refreshed']=true; }
function get_site_transient($name) {
 if(empty($GLOBALS['refreshed'])) throw new RuntimeException('No refresh');
 return (object)array('response'=>$GLOBALS['scenario']==='upstream'?array('subscription/subscription.php'=>(object)array('new_version'=>'fabricated')):array(),
 'no_update'=>$GLOBALS['scenario']==='empty'?array():array('hello.php'=>(object)array('new_version'=>'fabricated')));
}
function wp_json_encode($value,$flags=0) { return json_encode($value,$flags); }
$GLOBALS['scenario']=$argv[3];
$args=array($argv[1]);
require $argv[2];
'''
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            entry = root / 'fixture.php'
            entry.write_text(harness)
            for scenario in ('valid', 'header', 'upstream', 'empty'):
                result = subprocess.run(['php', str(entry), str(root), str(checker), scenario],
                                        capture_output=True, text=True)
                if scenario == 'valid':
                    self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                    report = __import__('json').loads((root / 'update-isolation.json').read_text())
                    self.assertFalse(report['upstream_offer_present'])
                    self.assertEqual(report['unrelated_checked'], ['hello.php'])
                else:
                    self.assertNotEqual(result.returncode, 0, scenario)

    def test_rejects_unsafe_members(self):
        module = self.load_module()
        for member in ['../escaped.php', 'subscription/../escaped.php', 'plugin/subscription.php',
                       '/subscription/file.php', 'subscription\\file.php', 'subscription/C:/file.php']:
            with self.subTest(member=member), tempfile.TemporaryDirectory() as temp:
                package = Path(temp) / 'fabricated.zip'
                with zipfile.ZipFile(package, 'w') as archive:
                    archive.writestr('subscription/subscription.php', 'fixture')
                    info = zipfile.ZipInfo('fixture')
                    info.filename = member  # Preserve malformed token even on Windows.
                    archive.writestr(info, 'fixture')
                digest = hashlib.sha256(package.read_bytes()).hexdigest()
                with self.assertRaises(ValueError):
                    module.validate_package(package, digest)

    def test_http_runner_accepts_only_explicit_roots(self):
        runner = SCRIPT.with_name('run-integration.sh')
        fake = r'''curl() {
case "$*" in
 *security-boundaries*) printf '{"success":true}\n200';;
 *wp-json/wp/v2/plugins*) printf '[{"plugin":"%s\\/subscription","status":"active"},{"plugin":"woocommerce\\/woocommerce","status":"active"},{"plugin":"woocommerce-gateway-stripe\\/woocommerce-gateway-stripe","status":"active"}]' "${ASHBI_INTEGRATION_PLUGIN_DIR:-plugin}";;
 *wp-admin/index.php*) printf 'wpApiSettings = {"nonce":"offline-fixture"}';;
esac
}; sleep() { :; }; export -f curl sleep
bash "$1"
'''
        for root in ['plugin', 'subscription', '../subscription', 'unknown', 'subscription;bad']:
            with self.subTest(root=root):
                env = dict(os.environ, ASHBI_INTEGRATION_PLUGIN_DIR=root)
                result = subprocess.run([shutil.which('bash'), '-c', fake, 'fixture', runner.as_posix()],
                                        env=env, capture_output=True, text=True, timeout=8)
                if root in ('plugin', 'subscription'):
                    self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
                else:
                    self.assertEqual(result.returncode, 2, result.stderr + result.stdout)
        result = subprocess.run([shutil.which('bash'), runner.as_posix()], capture_output=True, text=True,
                                env=dict(os.environ, ASHBI_PLAYGROUND_HPOS_MODE='invalid'), timeout=8)
        self.assertEqual(result.returncode, 2)

    def test_owned_environment_failure_cleanup_and_reports(self):
        module = self.load_module()
        self.assertTrue(hasattr(module, 'prepare_environment'), 'Owned environment orchestration missing')
        for failure in [None, 'start', 'install', 'integration']:
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as temp:
                output = Path(temp) / 'reports'
                output.mkdir()
                (output / 'candidate.zip').write_bytes(b'offline fixture only')
                env = module.prepare_environment(output, SCRIPT.parent.parent)
                calls = []

                def invoke(command, **kwargs):
                    calls.append(command)
                    text = ' '.join(command)
                    if 'plugin check' in text:
                        (output / 'plugin-check.stdout').write_text('[]')
                        (output / 'plugin-check.exit-code').write_text('1')
                        return subprocess.CompletedProcess(command, 1, '', '')
                    failed = ((failure == 'start' and 'start' in command)
                              or (failure == 'install' and 'install' in command)
                              or (failure == 'integration' and Path(command[0]).name.lower() in ('bash', 'bash.exe')))
                    if failed:
                        raise subprocess.CalledProcessError(1, command, output='offline failure', stderr='fabricated diagnostic')
                    return subprocess.CompletedProcess(command, 0, '{"success":true}\n', '')

                if failure:
                    with self.assertRaises(subprocess.CalledProcessError):
                        module.run_environment(env, output, 'off', 'runtime', invoke)
                else:
                    module.run_environment(env, output, 'on', 'plugin-check', invoke)
                    self.assertEqual((output / 'plugin-check.exit-code').read_text(), '1')
                if failure == 'integration':
                    self.assertEqual((output / 'integration.stderr').read_text(), 'fabricated diagnostic')
                    self.assertEqual((output / 'integration.exit-code').read_text(), '1')
                self.assertTrue(any('cleanup' in command for command in calls), 'Cleanup must preserve shared Docker images')
                self.assertFalse(any('destroy' in command for command in calls))
                self.assertFalse((output / 'owned-environment').exists())
                self.assertTrue((output / 'candidate.zip').exists())

    def test_preparation_preserves_overrides_and_refuses_unknown_owner(self):
        module = self.load_module()
        self.assertTrue(hasattr(module, 'prepare_environment'), 'Owned environment orchestration missing')
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            override = root / '.wp-env.override.json'
            override.write_text('{"port":9999}')
            output = root / 'reports'
            output.mkdir()
            env = module.prepare_environment(output, SCRIPT.parent.parent)
            config = __import__('json').loads(env['config'].read_text())
            self.assertNotIn('./plugin', config['plugins'])
            self.assertIn('ashbi-integration', config['mappings'])
            self.assertEqual(config['port'], 8888)
            self.assertEqual(override.read_text(), '{"port":9999}')
            with self.assertRaises(FileExistsError):
                module.prepare_environment(output, SCRIPT.parent.parent)
            env['config'].write_text('{}')
            with self.assertRaisesRegex(ValueError, 'ownership'):
                module.cleanup_environment(output, lambda *args, **kwargs: self.fail('Must not destroy unknown env'))

    def test_preflight_refuses_overrides_ports_and_invalid_hpos(self):
        module = self.load_module()
        self.assertTrue(hasattr(module, 'preflight'), 'Hosted preflight missing')
        for mode, inherited, bound in [('bad', {}, False), ('on', {'WP_ENV_PORT':'8889'}, False),
                                        ('off', {'WP_ENV_HOME':'unknown'}, False), ('off', {'WP_ENV_MYSQL_PORT':'3307'}, False), ('off', {}, True)]:
            with self.subTest(mode=mode, inherited=inherited, bound=bound):
                with self.assertRaises((ValueError, RuntimeError)):
                    module.preflight(mode, inherited, lambda: bound)

    def test_php_installed_byte_readback(self):
        checker = SCRIPT.parent / 'package-validation/verify-installed.php'
        harness = r'''<?php
namespace Automattic\WooCommerce\Utilities {
class OrderUtil { static function custom_orders_table_usage_is_enabled() { return false; } }
}
namespace {
class WP_CLI {
 static function error($message) { throw new \RuntimeException($message); }
 static function success($message) { print $message; }
}
function wp_get_environment_type() { return 'local'; }
function plugin_basename($file) { return 'subscription/' . basename($file); }
function is_plugin_active($name) { return $name === 'subscription/subscription.php'; }
function get_bloginfo($field) { return 'offline-fixture'; }
function wp_json_encode($value,$flags=0) { return json_encode($value,$flags); }
define('WP_PLUGIN_DIR', $argv[1] . '/plugins');
define('SUBSCRPT_FILE', WP_PLUGIN_DIR . '/subscription/subscription.php');
define('WC_VERSION', 'offline-fixture');
$args = array($argv[1]);
require $argv[2];
}
'''
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp)
            plugin = output / 'plugins/subscription'
            plugin.mkdir(parents=True)
            main = plugin / 'subscription.php'
            main.write_text('<?php // fabricated fixture')
            nested = plugin / 'includes/bootstrap.php'
            nested.parent.mkdir()
            nested.write_text('<?php // fabricated nested fixture')
            (output / 'members.json').write_text(__import__('json').dumps({
                'subscription/subscription.php': hashlib.sha256(main.read_bytes()).hexdigest(),
                'subscription/includes/bootstrap.php': hashlib.sha256(nested.read_bytes()).hexdigest()}))
            (output / 'provenance.json').write_text('{"hpos":"off"}')
            entry = output / 'fixture.php'
            entry.write_text(harness)
            result = subprocess.run(['php', str(entry), str(output), str(checker)],
                                    capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
            self.assertEqual(__import__('json').loads((output/'installed-package.json').read_text())['files_verified'], 2)
            main.write_text('changed bytes')
            result = subprocess.run(['php', str(entry), str(output), str(checker)],
                                    capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('Installed package bytes differ', result.stderr + result.stdout)

    def test_plugin_check_machine_report_preserves_findings_and_no_findings_message(self):
        module = self.load_module()
        self.assertTrue(hasattr(module, 'parse_plugin_check'), 'Official CLI output adapter missing')
        findings = [{'file':'subscription.php', 'type':'ERROR', 'code':'fabricated'}]
        self.assertEqual(module.parse_plugin_check(__import__('json').dumps(findings), 0), findings)
        self.assertEqual(module.parse_plugin_check('Success: Checks complete. No errors found.\n', 0), [])
        with self.assertRaises(ValueError):
            module.parse_plugin_check('Success: Checks complete. No errors found.', 1)
        with self.assertRaises(ValueError):
            module.parse_plugin_check('unexpected PHP failure', 0)

    def test_canonical_package_validation(self):
        self.assertTrue(SCRIPT.exists(), 'Exact published package validator is missing')
        spec = importlib.util.spec_from_file_location('release_validator', SCRIPT)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        with tempfile.TemporaryDirectory() as temp:
            package = Path(temp) / 'fabricated.zip'
            with zipfile.ZipFile(package, 'w') as archive:
                archive.writestr('subscription/subscription.php', '<?php // fabricated offline fixture')
            digest = hashlib.sha256(package.read_bytes()).hexdigest()
            manifest = module.validate_package(package, digest)
            self.assertEqual(list(manifest), ['subscription/subscription.php'])
            with self.assertRaisesRegex(ValueError, 'checksum'):
                module.validate_package(package, '0' * 64)


if __name__ == '__main__':
    unittest.main()
