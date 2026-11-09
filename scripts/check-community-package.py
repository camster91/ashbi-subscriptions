"""Check an exact community ZIP locally. Uses isolated PHP doubles, NOT WordPress."""
import argparse
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('community', ROOT / 'scripts/build-community-package.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


def run(command):
    result = subprocess.run(command, capture_output=True, check=True)
    return result.stdout.decode('utf-8').strip()


def check(canonical_path, community_path):
    canonical = builder.read_archive(canonical_path)
    actual = builder.read_archive(community_path)
    builder.validate_canonical(canonical)
    builder.verify_members(canonical, actual)
    main_name = 'ashbi-subscriptions/ashbi-subscriptions.php'
    main = actual[main_name]
    if b'Update URI:' in main or b'Text Domain: ashbi-subscriptions' not in main:
        raise ValueError('Invalid community directory header')
    if any(not name.startswith('ashbi-subscriptions/') for name in actual):
        raise ValueError('Community contains a misleading root')
    if 'ashbi-subscriptions/subscription.php' in actual:
        raise ValueError('Community still has the compatibility main filename')
    for name, data in canonical.items():
        relative = name.split('/', 1)[1]
        if relative == 'THIRD_PARTY_NOTICES.md':
            target = 'ashbi-subscriptions/THIRD_PARTY_NOTICES.txt'
        else:
            target = 'ashbi-subscriptions/' + relative
        if relative.startswith(('vendor/', 'licenses/')) or relative in ('LICENSE', 'LICENSE.txt', 'THIRD_PARTY_NOTICES.md', 'composer.json'):
            if actual[target] != data:
                raise ValueError('Legal/dependency bytes changed: ' + relative)
    scratch = ROOT / 'dist'
    scratch.mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='community-check-', dir=scratch) as temporary:
        directory = Path(temporary)
        for name, data in actual.items():
            path = directory / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(data)
        plugin = directory / 'ashbi-subscriptions'
        main_path = plugin / 'ashbi-subscriptions.php'
        php_files = list(plugin.rglob('*.php'))
        for path in php_files:
            run(['php', '-l', str(path)])
        js_files = list(plugin.rglob('*.js'))
        for path in js_files:
            if builder.domain_transform(path.name, path.read_bytes()) != path.read_bytes():
                raise ValueError('Legacy JS translation domain remains: ' + str(path))
        for path in php_files:
            if 'vendor' not in path.parts and builder.domain_transform(path.name, path.read_bytes()) != path.read_bytes():
                raise ValueError('Legacy PHP translation domain remains: ' + str(path))
        # Run canonical static storage/hook contracts on extracted community bytes.
        contract = (ROOT / 'tests/compatibility-contract.php').read_text()
        contract = builder.replace_once(contract.encode(), b"$ashbi_plugin_directory = $root . '/plugin';", ("$ashbi_plugin_directory = '" + plugin.as_posix() + "';").encode())
        contract = builder.replace_once(contract, b"'/subscription.php'", b"'/ashbi-subscriptions.php'")
        contract_path = directory / 'community-contract.php'
        contract_path.write_bytes(contract)
        contract_result = run(['php', str(contract_path)])
        # Reuse the existing minimal WordPress-stub fixture; only main path differs.
        fixture = (ROOT / 'tests/fixtures/coexistence.php').read_bytes()
        fixture = builder.replace_once(fixture, b"realpath( dirname( __DIR__, 2 ) . '/plugin/subscription.php' )", ("realpath( '" + main_path.as_posix() + "' )").encode())
        fixture_path = directory / 'coexistence.php'
        fixture_path.write_bytes(fixture)
        (directory / 'foreign-subscription.php').write_bytes((ROOT / 'tests/fixtures/foreign-subscription.php').read_bytes())
        scenarios = ['clean', 'class', 'constant', 'subscrpt', 'own']
        for scenario in scenarios:
            if run(['php', str(fixture_path), scenario]) != 'PASS':
                raise ValueError('Packaged isolated coexistence fixture failed: ' + scenario)
        observer = (ROOT / 'tests/fixtures/bootstrap-direct-access.php').read_bytes()
        observer = builder.replace_once(observer, b'/plugin/vendor/', b'/ashbi-subscriptions/vendor/')
        observer_path = directory / 'observer.php'
        observer_path.write_bytes(observer)
        if run(['php', '-d', 'auto_prepend_file=' + str(observer_path), str(plugin / 'bootstrap.php')]) != 'PASS':
            raise ValueError('Direct bootstrap access loaded vendor files')
        if run(['php', str(main_path)]) != '':
            raise ValueError('Direct main access was not silent')
    return {'scope': 'local archive + PHP stubs; not real WordPress/HPOS/database rollback',
            'members': len(actual), 'php_linted': len(php_files), 'js_parsed': len(js_files),
            'coexistence_scenarios': scenarios, 'static_storage_contract': contract_result,
            'direct_access': 'main silent; bootstrap vendor-not-loaded',
            'preservation': 'exact approved transform; legal/vendor bytes identical'}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--canonical', type=Path, required=True)
    parser.add_argument('--community', type=Path, required=True)
    parser.add_argument('--report', type=Path)
    args = parser.parse_args()
    report = check(args.canonical, args.community)
    text = json.dumps(report, indent=2) + '\n'
    if args.report:
        args.report.write_text(text, encoding='utf-8')
    print(text)
