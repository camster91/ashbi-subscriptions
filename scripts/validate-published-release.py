#!/usr/bin/env python3
"""Validate an immutable historical ZIP or an explicitly identified source candidate."""
import argparse
import hashlib
import json
import os
import re
from pathlib import Path
import shutil
import socket
import subprocess
import uuid
import zipfile

REPO = Path(__file__).resolve().parent.parent

RELEASE_URL = ('https://github.com/camster91/ashbi-subscriptions/releases/download/'
               'v2.1.2-rc.2/ashbi-subscriptions-2.1.2-rc.2.zip')
RELEASE_SHA256 = 'd3021e5b019cafe2e1d149277b91509d2ba106355fb53d308e90d73774b4a054'
HISTORICAL_FIXTURE_COMMIT = '45683b68787afa784775ef6b57c01568f33ed578'
HISTORICAL_FIXTURE_SHA256 = '3b1ba8688be765febb51bfcaea6e6db70d0b5451274b3e81e4f1ec759f397e0b'


def read_historical_fixture(repo, revision):
    return subprocess.check_output(['git', '-C', str(repo), 'show', revision +
                                    ':plugin/tests/integration/security-boundaries.php'])


def prepare_historical_fixture(output, repo, read=read_historical_fixture):
    """Use the release-era suite, never silently skip new feature assertions."""
    source = read(repo, HISTORICAL_FIXTURE_COMMIT)
    if hashlib.sha256(source).hexdigest() != HISTORICAL_FIXTURE_SHA256:
        raise ValueError('Historical fixture checksum mismatch')
    target = output / 'historical-integration'
    target.mkdir(parents=True, exist_ok=False)
    (target / 'security-boundaries.php').write_bytes(source)
    return target


def validate_candidate_package(package, expected, plugin):
    """Candidate bytes must match the current source, including missing/extra files."""
    manifest = validate_package(package, expected)
    excluded = {'assets/images/logo.png', 'assets/images/logo-title.svg',
                'assets/images/icons/subscription-20.png', 'assets/images/icons/subscription-20-gray.png',
                'assets/images/subscrpt-ads.png', 'assets/images/woocommerce.png', 'assets/images/icons/crown.svg'}
    source = {}
    for path in plugin.rglob('*'):
        if path.is_symlink():
            raise ValueError('Candidate source contains a symbolic link')
        name = path.relative_to(plugin).as_posix()
        if (not path.is_file() or '.DS_Store' in path.parts or 'tests' in path.relative_to(plugin).parts
                or name in excluded or name.startswith(('assets/images/integrations/', 'assets/images/other_plugins/'))):
            continue
        source['subscription/' + name] = hashlib.sha256(path.read_bytes()).hexdigest()
    if manifest != source:
        raise ValueError('Candidate ZIP does not match exact source members')
    header = (plugin / 'subscription.php').read_text(encoding='utf-8')
    version = re.search(r'^ \* Version: (\S+)', header, re.MULTILINE)
    if not version:
        raise ValueError('Candidate version is unavailable')
    return manifest, version.group(1)


PLUGIN_CHECK_URL = 'https://downloads.wordpress.org/plugin/plugin-check.2.1.0.zip'
PLUGIN_CHECK_SHA256 = '6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4'


def parse_plugin_check(raw, status):
    # PCP 2.1 emits a success line, even with strict-json, when there are no findings.
    if status == 0 and raw.strip() == 'Success: Checks complete. No errors found.':
        return []
    findings = json.loads(raw)
    if not isinstance(findings, list) or any(not isinstance(row, dict) for row in findings):
        raise ValueError('Plugin Check did not produce a finding array')
    return findings


def validate_package(package, expected):
    if hashlib.sha256(package.read_bytes()).hexdigest() != expected:
        raise ValueError('Release checksum mismatch')
    with zipfile.ZipFile(package) as archive:
        manifest = {}
        seen = set()
        for member in archive.infolist():
            name = member.orig_filename
            parts = name.rstrip('/').split('/')
            if (parts[0] != 'subscription' or any(p in ('', '.', '..') for p in parts)
                    or '\\' in name or ':' in name or '\x00' in name
                    or name in seen or (member.external_attr >> 16) & 0o170000 == 0o120000):
                raise ValueError('Unsafe or noncanonical ZIP member: ' + name)
            seen.add(name)
            if not member.is_dir():
                manifest[name] = hashlib.sha256(archive.read(member)).hexdigest()
        if 'subscription/subscription.php' not in manifest:
            raise ValueError('Canonical subscription/subscription.php is missing')
        return manifest


def port_in_use():
    with socket.socket() as listener:
        try:
            listener.bind(('0.0.0.0', 8888))
        except OSError:
            return True
    return False


def preflight(mode, inherited=None, occupied=port_in_use):
    if mode not in ('on', 'off'):
        raise ValueError('HPOS must be on or off')
    inherited = os.environ if inherited is None else inherited
    if any(value for key, value in inherited.items() if key.upper().startswith(('WP_ENV_', 'COMPOSE_'))):
        raise ValueError('Refusing inherited wp-env or Compose ownership/configuration overrides')
    if occupied():
        raise RuntimeError('Refusing occupied port 8888; never stop an unknown environment')


def prepare_environment(output, repo, integration_dir=None):
    owned = output / 'owned-environment'
    owned.mkdir()  # Refuse to adopt an earlier or unknown environment.
    mu = owned / 'mu'
    mu.mkdir()
    shutil.copyfile(repo / 'scripts/playground-mu-plugins/ashbi-integration-loader.php',
                    mu / 'ashbi-integration-loader.php')
    shutil.copyfile(repo / 'scripts/package-validation/safety.php', mu / 'safety.php')
    config = owned / '.wp-env.json'
    config.write_text(json.dumps({
        'plugins': [
            'https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip',
            'https://downloads.wordpress.org/plugin/woocommerce-gateway-stripe.latest-stable.zip'],
        'phpVersion': '8.2', 'port': 8888, 'testsEnvironment': False,
        'mappings': {'wp-content/mu-plugins': mu.as_posix(),
                     'ashbi-integration': (integration_dir or repo / 'plugin/tests/integration').as_posix(),
                     'ashbi-tools': (repo / 'scripts/package-validation').as_posix(),
                     'ashbi-release': output.as_posix()},
        'config': {'WP_ENVIRONMENT_TYPE': 'local', 'WP_DEBUG': True, 'WP_DEBUG_LOG': True,
                   'DISABLE_WP_CRON': True, 'ACTION_SCHEDULER_DISABLE_DEFAULT_QUEUE_RUNNER': True}
    }, indent=2), encoding='utf-8')
    marker = {'id': str(uuid.uuid4()), 'config_sha256': hashlib.sha256(config.read_bytes()).hexdigest()}
    (owned / '.ashbi-owned.json').write_text(json.dumps(marker), encoding='utf-8')
    return environment_details(output, repo)


def environment_details(output, repo=REPO):
    owned = output / 'owned-environment'
    config = owned / '.wp-env.json'
    marker = json.loads((owned / '.ashbi-owned.json').read_text(encoding='utf-8'))
    if (str(uuid.UUID(marker['id'])) != marker['id'] or owned.is_symlink()
            or config.is_symlink() or (owned / 'cache').is_symlink()
            or marker['config_sha256'] != hashlib.sha256(config.read_bytes()).hexdigest()
            or (owned / '.wp-env.override.json').exists()):
        raise ValueError('Refusing environment with unknown ownership/configuration')
    return {'owned': owned, 'config': config,
            'command': [shutil.which('node') or 'node', '--require',
                        (repo / 'scripts/wp-env-compat.cjs').as_posix(),
                        (repo / 'node_modules/@wordpress/env/bin/wp-env').as_posix(),
                        '--config', config.as_posix()],
            'environ': dict({key: value for key, value in os.environ.items()
                             if not key.upper().startswith(('WP_ENV_', 'COMPOSE_'))},
                            WP_ENV_HOME=(owned / 'cache').as_posix(),
                            COMPOSE_PROJECT_NAME='ashbi-published-' + marker['id'].replace('-', ''),
                            COMPOSE_DISABLE_ENV_FILE='1')}


def cleanup_environment(output, invoke=subprocess.run):
    if not (output / 'owned-environment').exists():
        return
    env = environment_details(output)
    invoke(env['command'] + ['cleanup', '--force'], env=env['environ'],
           check=True, timeout=180)
    shutil.rmtree(env['owned'])


def run_environment(env, output, mode, kind, invoke=subprocess.run, target="published"):
    def run(args, **kwargs):
        if args[0] == 'run':
            args = args[:2] + ['--'] + args[2:]
        return invoke(env['command'] + args, env=env['environ'],
                      timeout=600, check=kwargs.pop('check', True), **kwargs)

    try:
        run(['start'])
        run(['run', 'cli', 'wp', 'plugin', 'install', '/var/www/html/ashbi-release/candidate.zip', '--activate'])
        run(['run', 'cli', 'wp', 'plugin', 'is-active', 'subscription'])
        run(['run', 'cli', 'wp', 'option', 'update', 'woocommerce_custom_orders_table_enabled',
             'yes' if mode == 'on' else 'no'])
        run(['run', 'cli', 'wp', 'eval-file', '/var/www/html/ashbi-tools/verify-installed.php'])
        run(['run', 'cli', 'wp', 'eval-file', '/var/www/html/ashbi-tools/verify-update-isolation.php'])
        if kind == 'plugin-check':
            run(['run', 'cli', 'wp', 'plugin', 'install',
                 '/var/www/html/ashbi-release/plugin-check.zip', '--activate'])
            run(['run', 'cli', 'wp', 'plugin', 'is-active', 'plugin-check'])
        run(['run', 'cli', 'bash', '-c',
             'wp core version > /var/www/html/ashbi-release/wordpress-version.txt && '
             'wp plugin list --format=json > /var/www/html/ashbi-release/installed-plugins.json'])
        if kind == 'runtime':
            try:
                result = invoke([shutil.which('bash') or 'bash', (REPO / 'scripts/run-integration.sh').as_posix()],
                                env=dict(os.environ, ASHBI_INTEGRATION_PLUGIN_DIR='subscription',
                                         ASHBI_PLAYGROUND_HPOS_MODE=mode),
                                check=True, capture_output=True, text=True, timeout=600)
            except subprocess.CalledProcessError as error:
                (output / 'integration.stdout').write_text(error.stdout or '', encoding='utf-8')
                (output / 'integration.stderr').write_text(error.stderr or '', encoding='utf-8')
                (output / 'integration.exit-code').write_text(str(error.returncode), encoding='utf-8')
                raise
            (output / 'integration.json').write_text(result.stdout, encoding='utf-8')
            (output / 'integration.exit-code').write_text(str(result.returncode), encoding='utf-8')
            response = json.loads(result.stdout)
            if response.get('success') is not True:
                raise RuntimeError('Integration callback did not report success')
            trace = response.get('data', {})
            if trace.get('hpos_mode') != mode or trace.get('hpos_enabled') is not (mode == 'on'):
                raise RuntimeError('Integration callback did not verify the requested actual datastore')
            if target == 'candidate' and (trace.get('billing_consent_readbacks') is not True
                                          or trace.get('cancellation_mysql_contention') is not True):
                raise RuntimeError('Candidate integration did not verify current feature coverage')
        else:
            run(['run', 'cli', 'bash', '-c',
                 'wp plugin list-checks --format=json > /var/www/html/ashbi-release/plugin-check-checks.json'])
            # Redirect inside Docker so wp-env progress output cannot corrupt JSON.
            result = run(['run', 'cli', 'bash', '-c',
                          'wp plugin check subscription/subscription.php --format=strict-json --fields=file,line,column,type,code,message,docs '
                          '--require=./wp-content/plugins/plugin-check/cli.php '
                          '> /var/www/html/ashbi-release/plugin-check.stdout 2> /var/www/html/ashbi-release/plugin-check.stderr; '
                          'status=$?; printf "%s\\n" "$status" > /var/www/html/ashbi-release/plugin-check.exit-code; '
                          'exit "$status"'], check=False)
            status = int((output / 'plugin-check.exit-code').read_text().strip())
            # Findings are diagnostic; missing/invalid machine output is infrastructure failure.
            findings = parse_plugin_check((output / 'plugin-check.stdout').read_text(encoding='utf-8'), status)
            (output / 'plugin-check.json').write_text(json.dumps(findings, indent=2), encoding='utf-8')
            errors = sum(row.get('type') == 'ERROR' for row in findings)
            warnings = sum(row.get('type') == 'WARNING' for row in findings)
            (output / 'plugin-check.policy-exit-code').write_text(str(status or (1 if errors else 0)), encoding='utf-8')
            (output / 'plugin-check-summary.json').write_text(json.dumps({
                'policy': 'nonblocking initial diagnostic; no findings suppressed',
                'wp_cli_exit_code': status, 'wp_env_exit_code': result.returncode,
                'runtime_bootstrap': True, 'errors': errors, 'warnings': warnings,
                'format': 'strict-json; raw stdout preserved; exact no-findings success line normalized to []'}), encoding='utf-8')
            print('Plugin Check diagnostic exit status:', status, flush=True)
        run(['run', 'cli', 'wp', 'eval-file', '/var/www/html/ashbi-tools/verify-installed.php'])
    finally:
        cleanup_environment(output, invoke)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('operation', choices=('run', 'cleanup', 'verify'))
    parser.add_argument('--output', type=Path, default=Path('reports/published-release'))
    parser.add_argument('--hpos', choices=('off', 'on'), default='off')
    parser.add_argument('--kind', choices=('runtime', 'plugin-check'), default='runtime')
    parser.add_argument('--package', type=Path, help='Verify only: local exact public ZIP')
    parser.add_argument('--target', choices=('published', 'candidate'), default='published')
    parser.add_argument('--expected-sha256', help='Required checksum for a local source candidate')
    parser.add_argument('--source-commit', help='Required exact current checkout commit for a source candidate')
    args = parser.parse_args()
    output = args.output.resolve()
    if args.operation == 'cleanup':
        cleanup_environment(output)
        return
    if args.operation == 'verify':
        if not args.package:
            parser.error('--package is required for verify')
        manifest = validate_package(args.package, RELEASE_SHA256)
        print(json.dumps({'sha256': RELEASE_SHA256, 'members': len(manifest)}))
        return
    preflight(args.hpos)
    # An unhealthy container may publish a port without a live listener.
    published = subprocess.run(['docker', 'ps', '--filter', 'publish=8888', '--format', '{{.ID}}'],
                               check=True, capture_output=True, text=True, timeout=30)
    if published.stdout.strip():
        raise RuntimeError('Refusing an unknown Docker environment publishing port 8888')
    output.mkdir(parents=True, exist_ok=False)
    if args.target == 'candidate':
        if (not args.package or not args.expected_sha256 or not args.source_commit
                or not re.fullmatch('[0-9a-f]{64}', args.expected_sha256)
                or not re.fullmatch('[0-9a-f]{40}', args.source_commit)):
            parser.error('Candidate run requires package, expected-sha256 and source-commit')
        actual_commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=REPO, text=True).strip()
        if actual_commit != args.source_commit:
            raise ValueError('Candidate checkout commit mismatch')
        shutil.copyfile(args.package.resolve(), output / 'candidate.zip')
        manifest, version = validate_candidate_package(output / 'candidate.zip', args.expected_sha256, REPO / 'plugin')
        digest, url, fixture_dir = args.expected_sha256, None, REPO / 'plugin/tests/integration'
    else:
        subprocess.run(['curl', '--fail', '--location', '--silent', '--show-error', '--max-time', '120',
                        '--output', (output / 'candidate.zip').as_posix(), RELEASE_URL], check=True, timeout=150)
        manifest = validate_package(output / 'candidate.zip', RELEASE_SHA256)
        digest, url, version = RELEASE_SHA256, RELEASE_URL, '2.1.2'
        fixture_dir = prepare_historical_fixture(output, REPO)
    (output / 'members.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
    (output / 'provenance.json').write_text(json.dumps({
        'target': args.target, 'url': url, 'sha256': digest, 'version': version,
        'source_commit': args.source_commit if args.target == 'candidate' else HISTORICAL_FIXTURE_COMMIT,
        'installed_basename': 'subscription/subscription.php',
        'hpos': args.hpos, 'kind': args.kind,
        'harness_commit': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=REPO, text=True).strip(),
        'fixture_sha256': hashlib.sha256((fixture_dir / 'security-boundaries.php').read_bytes()).hexdigest()
    }, indent=2), encoding='utf-8')
    if args.kind == 'plugin-check':
        subprocess.run(['curl', '--fail', '--location', '--silent', '--show-error', '--max-time', '120',
                        '--output', (output / 'plugin-check.zip').as_posix(), PLUGIN_CHECK_URL], check=True, timeout=150)
        if hashlib.sha256((output / 'plugin-check.zip').read_bytes()).hexdigest() != PLUGIN_CHECK_SHA256:
            raise ValueError('Official Plugin Check checksum mismatch')
        (output / 'plugin-check-provenance.json').write_text(json.dumps({
            'url': PLUGIN_CHECK_URL, 'sha256': PLUGIN_CHECK_SHA256, 'version': '2.1.0'}), encoding='utf-8')
    env = prepare_environment(output, REPO, fixture_dir)
    run_environment(env, output, args.hpos, args.kind, target=args.target)


if __name__ == '__main__':
    main()
