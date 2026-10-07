#!/usr/bin/env python3
"""Hosted real WP validation of the single workflow-produced community ZIP.

Consumers verify and install downloaded artifact bytes; they never rebuild a
community candidate or mount the source plugin. Offline tests are not WP proof.
"""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import zipfile

REPO = Path(__file__).resolve().parents[1]


def load(name):
    spec = importlib.util.spec_from_file_location(name.replace('-', '_'), REPO / 'scripts' / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


builder = load('build-community-package')
release = load('validate-published-release')


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def source_commit():
    return subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=REPO, text=True).strip()


def transform_hashes():
    names = ('build-community-package.py', 'community-php-domains.php',
             'community-js-domains.cjs', 'community-installation.txt')
    return {name: digest(REPO / 'scripts' / name) for name in names}


def safe_members(package, root):
    result = {}
    with zipfile.ZipFile(package) as archive:
        for info in archive.infolist():
            name = info.orig_filename
            parts = name.split('/')
            if (parts[0] != root or any(part in ('', '.', '..') for part in parts)
                    or '\\' in name or ':' in name or '\x00' in name or name in result
                    or info.is_dir() or (info.external_attr >> 16) & 0o170000 == 0o120000):
                raise ValueError('Unsafe/noncanonical artifact member: ' + name)
            result[name] = archive.read(info)
    return result


def validate_candidate(bundle):
    bundle = Path(bundle)
    manifest = json.loads((bundle / 'community-candidate.manifest.json').read_text(encoding='utf-8'))
    if (manifest.get('distribution') != 'community-directory-candidate'
            or manifest.get('source_commit') != source_commit()
            or manifest.get('dirty_tree') is not False
            or manifest.get('transform_scripts_sha256') != transform_hashes()):
        raise ValueError('Candidate provenance does not match the clean workflow source/approved transform')
    for name, key in [('community-candidate.zip', 'archive_sha256'),
                      ('github-compatibility.zip', 'canonical_archive_sha256')]:
        if digest(bundle / name) != manifest.get(key):
            raise ValueError('Downloaded artifact checksum mismatch: ' + name)
    canonical = safe_members(bundle / 'github-compatibility.zip', 'subscription')
    community = safe_members(bundle / 'community-candidate.zip', 'ashbi-subscriptions')
    for members, key in [(canonical, 'canonical_members_sha256'), (community, 'members_sha256')]:
        if {n: hashlib.sha256(data).hexdigest() for n, data in members.items()} != manifest.get(key):
            raise ValueError('Downloaded artifact member manifest mismatch: ' + key)
    if len(community) != 211 or 'ashbi-subscriptions/ashbi-subscriptions.php' not in community:
        raise ValueError('Community artifact must contain the approved 211 members and exact main entry')
    # This rebuilds only the unchanged canonical package to enforce complete source
    # membership. It never builds or substitutes a community ZIP for the downloaded one.
    builder.validate_canonical(canonical)
    builder.verify_members(canonical, community)
    return manifest


def fixture_hashes():
    names = {'scripts/playground-mu-plugins/ashbi-integration-loader.php',
             'scripts/run-integration.sh', 'scripts/wp-env-compat.cjs'}
    # These entire directories are mounted, not only the primary entry points.
    for directory in ('plugin/tests/integration', 'scripts/package-validation'):
        names.update(path.relative_to(REPO).as_posix() for path in (REPO / directory).rglob('*') if path.is_file())
    return {name: digest(REPO / name) for name in sorted(names)}


def integrity_readback(expected, directories):
    after, mismatches = {}, []
    for name, (path, checksum) in expected.items():
        try:
            if path.is_symlink() or any(parent.is_symlink() for parent in path.parents):
                raise ValueError('Symbolic link in trusted input path')
            after[name] = digest(path)
        except (OSError, ValueError) as error:
            after[name] = {'error': type(error).__name__, 'message': str(error)}
        if after[name] != checksum:
            mismatches.append(name)
    for directory, names in directories.items():
        try:
            actual = {path.relative_to(directory).as_posix() for path in directory.rglob('*') if path.is_file() or path.is_symlink()}
            if actual != names:
                mismatches.append(str(directory) + ': member set changed')
        except OSError as error:
            mismatches.append(str(directory) + ': ' + str(error))
    return {'before': {name: checksum for name, (_, checksum) in expected.items()},
            'after': after, 'mismatches': mismatches,
            'fixture_and_candidate_unchanged': not mismatches}


def write_integrity(output, evidence):
    # Replace rather than follow a container-created report-file symlink.
    import os
    import tempfile
    if output.is_symlink() or any(parent.is_symlink() for parent in output.parents):
        raise ValueError('Refusing symlinked integrity report directory')
    with tempfile.NamedTemporaryFile(mode='w', encoding='utf-8', dir=output, delete=False) as report:
        json.dump(evidence, report, indent=2)
        temporary = report.name
    try:
        os.replace(temporary, output / 'harness-integrity.json')
    finally:
        Path(temporary).unlink(missing_ok=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('operation', choices=('verify', 'run', 'cleanup'))
    parser.add_argument('--bundle', type=Path, default=Path('candidate'))
    parser.add_argument('--output', type=Path, default=Path('reports/community'))
    parser.add_argument('--hpos', choices=('off', 'on'), default='off')
    parser.add_argument('--kind', choices=('runtime', 'plugin-check'), default='runtime')
    args = parser.parse_args()
    output = args.output.resolve()
    if args.operation == 'cleanup':
        release.cleanup_environment(output, profile='community')
        return
    manifest = validate_candidate(args.bundle.resolve())
    if args.operation == 'verify':
        print(json.dumps({'source_commit': manifest['source_commit'],
                          'sha256': manifest['archive_sha256'], 'members': len(manifest['members_sha256'])}))
        return
    release.preflight(args.hpos)
    published = subprocess.run(['docker', 'ps', '--filter', 'publish=8888', '--format', '{{.ID}}'],
                               check=True, capture_output=True, text=True, timeout=30)
    if published.stdout.strip():
        raise RuntimeError('Refusing unknown Docker environment publishing port 8888')
    output.mkdir(parents=True, exist_ok=False)
    shutil.copyfile(args.bundle / 'community-candidate.zip', output / 'candidate.zip')
    if digest(output / 'candidate.zip') != manifest['archive_sha256']:
        raise ValueError('Candidate copy checksum mismatch')
    before = fixture_hashes()
    (output / 'candidate.manifest.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
    (output / 'members.json').write_text(json.dumps(manifest['members_sha256'], indent=2), encoding='utf-8')
    (output / 'provenance.json').write_text(json.dumps({
        'distribution': 'community-directory-candidate', 'artifact': 'workflow-produced immutable candidate; not a public release',
        'source_commit': manifest['source_commit'], 'sha256': manifest['archive_sha256'],
        'canonical_archive_sha256': manifest['canonical_archive_sha256'],
        'installed_basename': 'ashbi-subscriptions/ashbi-subscriptions.php',
        'hpos': args.hpos, 'kind': args.kind, 'fixture_sha256': before,
        'directory_status': 'not submitted, listed, approved, or slug-reserved',
    }, indent=2), encoding='utf-8')
    if args.kind == 'plugin-check':
        subprocess.run(['curl', '--fail', '--location', '--silent', '--show-error', '--max-time', '120',
                        '--output', (output / 'plugin-check.zip').as_posix(), release.PLUGIN_CHECK_URL],
                       check=True, timeout=150)
        if digest(output / 'plugin-check.zip') != release.PLUGIN_CHECK_SHA256:
            raise ValueError('Official Plugin Check checksum mismatch')
        (output / 'plugin-check-provenance.json').write_text(json.dumps({
            'url': release.PLUGIN_CHECK_URL, 'sha256': release.PLUGIN_CHECK_SHA256, 'version': '2.1.0'}), encoding='utf-8')
    # Expected digests live only in this host process, never in a WP-writable report.
    expected = {name: (REPO / name, checksum) for name, checksum in before.items()}
    for name in ('candidate.zip', 'candidate.manifest.json', 'members.json', 'provenance.json'):
        expected['input/' + name] = (output / name, digest(output / name))
    if args.kind == 'plugin-check':
        expected['input/plugin-check.zip'] = (output / 'plugin-check.zip', release.PLUGIN_CHECK_SHA256)
    directories = {}
    for directory in ('plugin/tests/integration', 'scripts/package-validation'):
        path = REPO / directory
        directories[path] = {name[len(directory) + 1:] for name in before if name.startswith(directory + '/')}
    owned = release.owned_path(output, profile='community')
    for name, source in [('safety.php', 'scripts/package-validation/safety.php'),
                         ('ashbi-integration-loader.php', 'scripts/playground-mu-plugins/ashbi-integration-loader.php')]:
        expected['executed-mu/' + name] = (owned / 'mu' / name, before[source])
    directories[owned / 'mu'] = {'safety.php', 'ashbi-integration-loader.php'}
    errors = []
    evidence = {'fixture_and_candidate_unchanged': False, 'mismatches': ['Integrity readback did not complete']}
    def record_error(phase, error):
        errors.append((phase, error))
        evidence['errors'] = [{'phase': label, 'type': type(value).__name__, 'message': str(value)} for label, value in errors]
    try:
        env = release.prepare_environment(output, REPO, profile='community')
        initial = integrity_readback(expected, directories)
        if not initial['fixture_and_candidate_unchanged']:
            raise ValueError('Pre-start harness integrity mismatch: ' + str(initial['mismatches']))
        release.run_environment(env, output, args.hpos, args.kind, profile='community', defer_cleanup=True)
    except BaseException as error:
        record_error('validation', error)
    finally:
        try:
            evidence = integrity_readback(expected, directories)
            evidence['errors'] = [{'phase': label, 'type': type(value).__name__, 'message': str(value)} for label, value in errors]
            if not evidence['fixture_and_candidate_unchanged']:
                record_error('integrity', ValueError('Harness/trusted-input integrity mismatch: ' + str(evidence['mismatches'])))
            write_integrity(output, evidence)
        except BaseException as error:
            record_error('integrity-evidence', error)
        finally:
            # Readback precedes deletion; retry only the separate UUID/config-owned env.
            try:
                release.cleanup_environment(output, profile='community')
            except BaseException as error:
                record_error('cleanup', error)
            finally:
                try:
                    write_integrity(output, evidence)
                except BaseException as error:
                    record_error('final-evidence', error)
    if len(errors) == 1:
        raise errors[0][1]
    if errors:
        raise RuntimeError('; '.join(label + ': ' + type(error).__name__ + ': ' + str(error)
                                     for label, error in errors)) from errors[0][1]


if __name__ == '__main__':
    main()
