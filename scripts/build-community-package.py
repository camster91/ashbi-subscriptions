"""Build the distinct community archive without mutating canonical source."""
import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]


def domain_transform(name, data):
    """Apply parser-selected byte edits, preserving every other byte."""
    if name.endswith('.js'):
        command = ['node', str(ROOT / 'scripts/community-js-domains.cjs')]
    else:
        command = ['php', str(ROOT / 'scripts/community-php-domains.php')]
    result = subprocess.run(command, input=data, capture_output=True, check=True)
    edits = json.loads(result.stdout)
    for start, end, replacement in sorted(edits, reverse=True):
        if data[start:end] not in (b"'subscription'", b'"subscription"'):
            raise ValueError('Parser selected a non-domain literal')
        data = data[:start] + replacement.encode() + data[end:]
    return data


def replace_once(data, old, new):
    if data.count(old) != 1:
        raise ValueError('Reviewed metadata changed: ' + repr(old))
    return data.replace(old, new, 1)


def community_members(canonical):
    """Explicit presentation changes only; no business identifier replacement."""
    if 'subscription/subscription.php' not in canonical or 'subscription/bootstrap.php' not in canonical:
        raise ValueError('Canonical root/main/bootstrap missing')
    result = {}
    for member, original in sorted(canonical.items()):
        parts = PurePosixPath(member).parts
        if not parts or parts[0] != 'subscription' or '..' in parts or '\\' in member or member.endswith('/'):
            raise ValueError('Invalid canonical member: ' + member)
        name = '/'.join(parts[1:])
        if name.startswith('tests/'):
            raise ValueError('Canonical package contains tests')
        # Do not relabel a stale POT as a fresh catalog. English-only candidate.
        if name == 'languages/subscription.pot':
            continue
        data = original
        if name.endswith(('.php', '.js')) and not name.startswith('vendor/'):
            data = domain_transform(name, data)
        if name == 'subscription.php':
            data = replace_once(data, b' * Update URI: false\n', b'')
            data = replace_once(data, b' * Text Domain: subscription', b' * Text Domain: ashbi-subscriptions')
            data = replace_once(data, b' * Plugin Name: Ashbi Subscriptions', b' * Plugin Name: Ashbi Subscriptions Community')
            data = replace_once(data, b' * Requires PHP: 7.4', b' * Requires Plugins: woocommerce\n * Requires PHP: 7.4')
            name = 'ashbi-subscriptions.php'
        elif name == 'THIRD_PARTY_NOTICES.md':
            name = 'THIRD_PARTY_NOTICES.txt'
        elif name == 'assets/css/installer.css':
            old = b'subscription/subscription.php'
            if data.count(old) != 2:
                raise ValueError('Reviewed installer basename selectors changed')
            data = data.replace(old, b'ashbi-subscriptions/ashbi-subscriptions.php')
        elif name == 'readme.txt':
            data = replace_once(data, b'=== Ashbi Subscriptions ===', b'=== Ashbi Subscriptions Community ===')
            start = data.index(b'== Installation ==')
            end = data.index(b'== Frequently Asked Questions ==')
            data = data[:start] + (ROOT / 'scripts/community-installation.txt').read_bytes() + data[end:]
            data = replace_once(data, b'= Will existing subscriptions remain available after an in-place upgrade? =', b'= Is this the GitHub in-place replacement ZIP? =')
            data = replace_once(data,
                b'The plugin intentionally retains the legacy directory, text domain, hooks, slugs,\noption keys, metadata keys, and database identifiers. Always back up and rehearse\nthe migration on staging before updating a live store.',
                b'No. The community package uses ashbi-subscriptions/ashbi-subscriptions.php and\nthe ashbi-subscriptions translation domain. It retains business storage, hooks,\nREST namespaces and compatibility aliases, not the old plugin basename. Back up\nand rehearse switching with all renewal workers paused on staging first.')
            data = replace_once(data, b'to upgrade existing installations in place.', b'to retain existing subscription records when switching packages on staging.')
        result[f'ashbi-subscriptions/{name}'] = data
    # New JS cache identity, same dependencies. Not a fabricated build output.
    for name in ('index', 'dashboard'):
        js_key = f'ashbi-subscriptions/build/{name}.js'
        asset_key = f'ashbi-subscriptions/build/{name}.asset.php'
        if js_key in result and asset_key in result:
            version = hashlib.sha256(result[js_key]).hexdigest()[:20].encode()
            data, count = re.subn(rb"('version'\s*=>\s*')[0-9a-f]+(')", lambda m: m[1] + version + m[2], result[asset_key])
            if count != 1:
                raise ValueError('Unexpected asset metadata')
            result[asset_key] = data
    return result


def verify_members(canonical, actual):
    expected = community_members(canonical)
    if expected.keys() != actual.keys():
        raise ValueError('Community member set differs from approved transform')
    for name in expected:
        if actual[name] != expected[name]:
            raise ValueError('Unapproved community byte changes: ' + name)


def read_archive(path):
    with zipfile.ZipFile(path) as archive:
        names = archive.namelist()
        if len(names) != len(set(names)) or archive.testzip() is not None:
            raise ValueError('Duplicate or corrupt ZIP members')
        return {name: archive.read(name) for name in names}


def validate_canonical(canonical):
    """Rebuild via the unchanged canonical policy; reject subsets and foreign bytes."""
    env = dict(os.environ)
    # Enable Windows/MSYS conversion only in this owned canonical subprocess.
    env.pop('MSYS_NO_PATHCONV', None)
    env.pop('MSYS2_ARG_CONV_EXCL', None)
    scratch = ROOT / 'dist'
    scratch.mkdir(exist_ok=True)
    bash = shutil.which('bash')
    if not bash:
        raise RuntimeError('Canonical packaging requires bash')
    with tempfile.TemporaryDirectory(prefix='community-source-', dir=scratch) as temporary:
        path = Path(temporary) / 'canonical.zip'
        # Bash builder accepts only absolute POSIX paths, not C:/ paths.
        destination = path.as_posix()
        if os.name == 'nt':
            destination = '/' + destination[0].lower() + destination[2:]
        subprocess.run([bash, 'scripts/build-release.sh', destination], cwd=ROOT, env=env, capture_output=True, check=True)
        if canonical != read_archive(path):
            raise ValueError('Input is not the complete canonical package from this source checkout')


def build(canonical_path, output):
    canonical = read_archive(canonical_path)
    validate_canonical(canonical)
    # Reject arbitrary source blobs. Fixture API is separate from CLI evidence.
    for name, data in canonical.items():
        if not name.startswith('subscription/'):
            raise ValueError('Wrong canonical root')
        relative = PurePosixPath(name).relative_to('subscription')
        if '..' in relative.parts or '\\' in name:
            raise ValueError('Unsafe canonical path')
        path = ROOT / 'plugin' / relative
        if not path.is_file() or path.read_bytes() != data:
            raise ValueError('Canonical member differs from source checkout: ' + name)
    members = community_members(canonical)
    output = Path(output)
    if output.resolve() == Path(canonical_path).resolve():
        raise ValueError('Refusing to overwrite the GitHub compatibility ZIP')
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(canonical_path) as source, zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        timestamp = source.getinfo('subscription/subscription.php').date_time
        for name, data in sorted(members.items()):
            info = zipfile.ZipInfo(name, timestamp)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            archive.writestr(info, data)
    verify_members(canonical, read_archive(output))
    digest = hashlib.sha256(output.read_bytes()).hexdigest()
    output.with_suffix('.sha256').write_bytes(f'{digest}  {output.name}\n'.encode('utf-8'))
    git = lambda *args: subprocess.check_output(['git', '-C', str(ROOT), *args], text=True).strip()
    scripts = [Path(__file__), ROOT / 'scripts/community-php-domains.php', ROOT / 'scripts/community-js-domains.cjs', ROOT / 'scripts/community-installation.txt']
    evidence = {
        'distribution': 'community-directory-candidate',
        'source_commit': git('rev-parse', 'HEAD'),
        'source_branch': git('branch', '--show-current'),
        'dirty_tree': bool(git('status', '--porcelain')),
        'canonical_archive_sha256': hashlib.sha256(Path(canonical_path).read_bytes()).hexdigest(),
        'archive_sha256': digest,
        'translation_policy': 'English only; inherited stale POT omitted; no MO/JSON catalogs fabricated',
        'transform_scripts_sha256': {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in scripts},
        'members_sha256': {name: hashlib.sha256(data).hexdigest() for name, data in sorted(members.items())},
        'canonical_members_sha256': {name: hashlib.sha256(data).hexdigest() for name, data in sorted(canonical.items())},
    }
    output.with_suffix('.manifest.json').write_text(json.dumps(evidence, indent=2) + '\n', encoding='utf-8')
    return evidence


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=['build', 'verify'])
    parser.add_argument('--canonical', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    if args.command == 'build':
        evidence = build(args.canonical, args.output)
        print(json.dumps({'archive': str(args.output), 'sha256': evidence['archive_sha256'], 'members': len(evidence['members_sha256']), 'dirty_tree': evidence['dirty_tree']}))
    else:
        canonical = read_archive(args.canonical)
        validate_canonical(canonical)
        verify_members(canonical, read_archive(args.output))
        print('Verified exact approved community transform; all other canonical bytes preserved.')


if __name__ == '__main__':
    main()
