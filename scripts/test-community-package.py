"""Local archive contracts; not WordPress/Plugin Check or database evidence."""
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class CommunityPackageTests(unittest.TestCase):
    def test_community_builder_exists(self):
        self.assertTrue((ROOT / 'scripts/build-community-package.py').is_file(),
                        'Distinct community builder is missing; do not change canonical GitHub builder')


    def test_independent_packaged_checker_exists(self):
        self.assertTrue((ROOT / 'scripts/check-community-package.py').is_file(),
                        'Independent archive syntax/bootstrap checker is missing')


class DomainTransformTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        spec = importlib.util.spec_from_file_location('community', ROOT / 'scripts/build-community-package.py')
        cls.builder = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.builder)

    def test_php_only_domain_argument_changes(self):
        source = b"<?php __('subscription', 'subscription'); _n('subscription','many',f(1,2),'subscription'); update_option('subscription', 'subscription'); $o->__('x','subscription');"
        expected = source.replace(b"'subscription'); _n", b"'ashbi-subscriptions'); _n", 1).replace(b"f(1,2),'subscription'", b"f(1,2),'ashbi-subscriptions'", 1)
        self.assertTrue(hasattr(self.builder, 'domain_transform'), 'Token/AST domain transform is missing')
        self.assertEqual(expected, self.builder.domain_transform('sample.php', source))


    def test_javascript_source_and_compiled_domain_only(self):
        source = b'import { __ as tr } from "@wordpress/i18n"; const i=window.wp.i18n; tr("subscription", "subscription"); (0,i.__)("x", "subscription"); wp.i18n.__("y", "subscription"); other.__("z", "subscription"); registerPlugin("subscription", {});'
        expected = source.replace(b'tr("subscription", "subscription")', b'tr("subscription", "ashbi-subscriptions")').replace(b'(0,i.__)("x", "subscription")', b'(0,i.__)("x", "ashbi-subscriptions")').replace(b'wp.i18n.__("y", "subscription")', b'wp.i18n.__("y", "ashbi-subscriptions")')
        self.assertEqual(expected, self.builder.domain_transform('sample.js', source))

    def test_composite_php_domain_is_rejected(self):
        import subprocess
        with self.assertRaises(subprocess.CalledProcessError):
            self.builder.domain_transform('sample.php', b"<?php __('x', 'subscription' . '-bad');")


    def test_distinct_package_and_roundtrip_preserve_business_bytes(self):
        self.assertTrue(hasattr(self.builder, 'community_members'), 'Community archive transform is missing')
        canonical = {
            'subscription/subscription.php': (ROOT / 'plugin/subscription.php').read_bytes(),
            'subscription/includes/Fixture.php': b"<?php __('subscription','subscription'); add_action('subscription','callback'); update_option('subscrpt_key','subscription');",
            'subscription/THIRD_PARTY_NOTICES.md': b'Copyright GPL legal notice\n',
            'subscription/languages/subscription.pot': b'legacy catalog',
            'subscription/bootstrap.php': b'<?php // bootstrap\n',
        }
        members = self.builder.community_members(canonical)
        main = members['ashbi-subscriptions/ashbi-subscriptions.php']
        self.assertNotIn(b'Update URI:', main)
        self.assertIn(b'Text Domain: ashbi-subscriptions', main)
        self.assertNotIn('subscription/subscription.php', members)
        self.assertNotIn('ashbi-subscriptions/languages/subscription.pot', members)
        self.assertEqual(canonical['subscription/THIRD_PARTY_NOTICES.md'], members['ashbi-subscriptions/THIRD_PARTY_NOTICES.txt'])
        self.assertEqual(b"<?php __('subscription','ashbi-subscriptions'); add_action('subscription','callback'); update_option('subscrpt_key','subscription');", members['ashbi-subscriptions/includes/Fixture.php'])
        self.builder.verify_members(canonical, members)
        members['ashbi-subscriptions/includes/Fixture.php'] = members['ashbi-subscriptions/includes/Fixture.php'].replace(b'subscrpt_key', b'new_key')
        with self.assertRaises(ValueError):
            self.builder.verify_members(canonical, members)


    def test_source_complete_member_set_required(self):
        self.assertTrue(hasattr(self.builder, 'validate_canonical'), 'Canonical completeness gate is missing')
        partial = {name: (ROOT / 'plugin' / name.split('/', 1)[1]).read_bytes() for name in ['subscription/subscription.php', 'subscription/bootstrap.php']}
        with self.assertRaisesRegex(ValueError, 'complete canonical'):
            self.builder.validate_canonical(partial)

    def test_wrong_root_and_changed_update_policy_refused(self):
        source = {'subscription/subscription.php': (ROOT / 'plugin/subscription.php').read_bytes(), 'subscription/bootstrap.php': b'<?php'}
        bad = dict(source)
        bad['subscription/subscription.php'] = bad['subscription/subscription.php'].replace(b'Update URI: false', b'Update URI: https://example.invalid')
        with self.assertRaises(ValueError):
            self.builder.community_members(bad)
        with self.assertRaises(ValueError):
            self.builder.community_members({k.replace('subscription/', 'wrong/', 1): v for k, v in source.items()})
        with self.assertRaises(ValueError):
            self.builder.community_members({**source, 'subscription/../unsafe.php': b'<?php'})


    def test_javascript_composite_domain_is_rejected(self):
        import subprocess
        with self.assertRaises(subprocess.CalledProcessError):
            self.builder.domain_transform('sample.js', b"wp.i18n.__('x', 'subscription' + '-bad');")

    def test_shadowed_javascript_alias_is_not_a_translation_call(self):
        source = b'const i=window.wp.i18n; function other(i) { return i.__("business", "subscription"); } i.__("ok", "subscription");'
        expected = source.replace(b'i.__("ok", "subscription")', b'i.__("ok", "ashbi-subscriptions")')
        self.assertEqual(expected, self.builder.domain_transform('sample.js', source))


    def test_duplicate_zip_members_are_rejected(self):
        import tempfile
        import warnings
        import zipfile
        (ROOT / 'dist').mkdir(exist_ok=True)
        with tempfile.TemporaryDirectory(dir=ROOT / 'dist') as temporary:
            archive = Path(temporary) / 'duplicate.zip'
            with warnings.catch_warnings():
                warnings.simplefilter('ignore', UserWarning)  # Expected fabricated ZIP duplication, not scan suppression.
                with zipfile.ZipFile(archive, 'w') as output:
                    output.writestr('subscription/bootstrap.php', b'<?php')
                    output.writestr('subscription/bootstrap.php', b'<?php // corrupt replacement')
            with self.assertRaisesRegex(ValueError, 'Duplicate'):
                self.builder.read_archive(archive)

    def test_misleading_community_header_and_extra_main_are_rejected(self):
        canonical = {'subscription/subscription.php': (ROOT / 'plugin/subscription.php').read_bytes(), 'subscription/bootstrap.php': b'<?php'}
        approved = self.builder.community_members(canonical)
        wrong_header = dict(approved)
        main = 'ashbi-subscriptions/ashbi-subscriptions.php'
        wrong_header[main] = wrong_header[main].replace(b'Text Domain: ashbi-subscriptions', b'Text Domain: subscription')
        with self.assertRaises(ValueError):
            self.builder.verify_members(canonical, wrong_header)
        with self.assertRaises(ValueError):
            self.builder.verify_members(canonical, {**approved, 'ashbi-subscriptions/subscription.php': approved[main]})


    def test_built_checksum_is_portable_lf(self):
        import os
        import shutil
        import subprocess
        import tempfile
        scratch = ROOT / 'dist'
        scratch.mkdir(exist_ok=True)
        with tempfile.TemporaryDirectory(dir=scratch) as temporary:
            canonical = Path(temporary) / 'canonical.zip'
            destination = canonical.as_posix()
            if os.name == 'nt':
                destination = '/' + destination[0].lower() + destination[2:]
            env = dict(os.environ)
            env.pop('MSYS_NO_PATHCONV', None)
            env.pop('MSYS2_ARG_CONV_EXCL', None)
            subprocess.run([shutil.which('bash'), 'scripts/build-release.sh', destination], cwd=ROOT, env=env, capture_output=True, check=True)
            output = Path(temporary) / 'community.zip'
            self.builder.build(canonical, output)
            checksum = output.with_suffix('.sha256').read_bytes()
            self.assertNotIn(b'\r', checksum, 'CRLF makes sha256sum treat CR as part of the archive filename')
            self.assertTrue(checksum.endswith(b'  community.zip\n'))


if __name__ == '__main__':
    unittest.main()
