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

    def test_javascript_loop_assignment_rejects_known_namespace(self):
        import subprocess
        sources = (
            b'let i=wp.i18n; for(i of [business]){} i.__("business","subscription");',
            b'let tr=wp.i18n.__; for(tr in business){} tr("business","subscription");',
            b'const i=wp.i18n; for(i.__ of things){} i.__("business","subscription");',
            b'const i=wp.i18n; for({x:i.__} of things){} i.__("business","subscription");',
            b'for(window.wp of things){} wp.i18n.__("business","subscription");',
            b'for([wp.i18n] of things){} wp.i18n.__("business","subscription");',
        )
        for source in sources:
            with self.subTest(source=source):
                with self.assertRaises(subprocess.CalledProcessError) as failure:
                    self.builder.domain_transform('sample.js', source)
                self.assertEqual(b'', failure.exception.stdout)

    def test_javascript_loop_let_shadow_does_not_escape(self):
        source = b'for(let wp of things) { wp.i18n.__("business","subscription"); } wp.i18n.__("label","subscription");'
        expected = source.replace(b'"label","subscription"', b'"label","ashbi-subscriptions"')
        self.assertEqual(expected, self.builder.domain_transform('sample.js', source))

    def test_javascript_sequence_function_alias_rewrites_domain(self):
        source = b'const tr=(0,wp.i18n.__); tr("label","subscription");'
        expected = source.replace(b'"label","subscription"', b'"label","ashbi-subscriptions"')
        self.assertEqual(expected, self.builder.domain_transform('sample.js', source))

    def test_javascript_named_class_expression_shadows_global_wp(self):
        source = b'const C=class wp { method(){ wp.i18n.__("business","subscription"); } };'
        self.assertEqual(source, self.builder.domain_transform('sample.js', source))

    def test_javascript_optional_and_static_computed_translation_domains(self):
        source = b'const i=window["wp"]?.i18n; const tr=(0,i["__"]); tr?.("label","subscription"); i?.["__"]("label","subscription");'
        expected = source.replace(b'"label","subscription"', b'"label","ashbi-subscriptions"')
        self.assertEqual(expected, self.builder.domain_transform('sample.js', source))

    def test_javascript_dynamic_computed_known_namespace_fails_closed(self):
        import subprocess
        for source in (
            b'const i=wp.i18n; i[method]("label","subscription");',
            b'const tr=wp.i18n[method]; tr("label","subscription");',
            b'window[which].i18n.__("label","subscription");',
        ):
            with self.subTest(source=source):
                with self.assertRaises(subprocess.CalledProcessError) as failure:
                    self.builder.domain_transform('sample.js', source)
                self.assertEqual(b'', failure.exception.stdout)

    def test_javascript_loop_var_is_function_scoped(self):
        source = b'function f(){ for(var wp of things){} wp.i18n.__("business","subscription"); } wp.i18n.__("label","subscription");'
        expected = source.replace(b'"label","subscription"', b'"label","ashbi-subscriptions"')
        self.assertEqual(expected, self.builder.domain_transform('sample.js', source))

    def test_javascript_unrelated_window_exports_preserve_bytes(self):
        source = '// café\nwindow.Chart=business; window.UI=business; wp.other=business; wp.i18n.__("subscription","subscription");'.encode()
        expected = source.replace(b'"subscription","subscription"', b'"subscription","ashbi-subscriptions"')
        self.assertEqual(expected, self.builder.domain_transform('sample.js', source))

    def test_php_shadowed_translation_identity_is_rejected(self):
        import subprocess
        for source in (
            b"<?php namespace Local; function __($value,$kind){return $kind;} __('business','subscription');",
            b"<?php namespace Local; use function Business\\kind as __; __('business','subscription');",
            b"<?php namespace Local; use function \\__ as tr; tr('label','subscription');",
        ):
            with self.subTest(source=source), self.assertRaises(subprocess.CalledProcessError):
                self.builder.domain_transform('sample.php', source)

    def test_php_namespaced_global_gettext_fallback_is_retained(self):
        source = b"<?php namespace SpringDevs\\Subscription; __('label','subscription'); \\__('label','subscription'); custom('business','subscription');"
        expected = source.replace(b"'label','subscription'", b"'label','ashbi-subscriptions'")
        self.assertEqual(expected, self.builder.domain_transform('sample.php', source))

    def test_javascript_known_aliases_rewrite_only_domains(self):
        source = b'const i=wp.i18n; const j=i; const tr=j.__; tr("subscription","subscription"); j.__("label","subscription"); business("subscription","subscription");'
        expected = source.replace(b'tr("subscription","subscription")', b'tr("subscription","ashbi-subscriptions")').replace(b'j.__("label","subscription")', b'j.__("label","ashbi-subscriptions")')
        self.assertEqual(expected, self.builder.domain_transform('sample.js', source))

    def test_php_only_domain_argument_changes(self):
        source = b"<?php __('subscription', 'subscription'); _n('subscription','many',f(1,2),'subscription'); update_option('subscription', 'subscription'); $o->__('x','subscription');"
        expected = source.replace(b"'subscription'); _n", b"'ashbi-subscriptions'); _n", 1).replace(b"f(1,2),'subscription'", b"f(1,2),'ashbi-subscriptions'", 1)
        self.assertTrue(hasattr(self.builder, 'domain_transform'), 'Token/AST domain transform is missing')
        self.assertEqual(expected, self.builder.domain_transform('sample.php', source))


    def test_javascript_hoisted_wp_shadow_preserves_business_call(self):
        source = b'const i=wp.i18n; i.__("business","subscription"); function wp() {}'
        self.assertEqual(source, self.builder.domain_transform('sample.js', source))

    def test_javascript_translation_member_writes_are_rejected(self):
        import subprocess
        for source in (
            b'const i=wp.i18n; i.__=business; i.__("business","subscription");',
            b'wp.i18n.__=business; wp.i18n.__("business","subscription");',
            b'wp.i18n=business; wp.i18n.__("business","subscription");',
            b'const i=wp.i18n; delete i.__; i.__("business","subscription");',
            b'const i=wp.i18n; i[method]=business; i.__("business","subscription");',
        ):
            with self.subTest(source=source), self.assertRaises(subprocess.CalledProcessError):
                self.builder.domain_transform('sample.js', source)

    def test_checker_cannot_certify_unsupported_or_invalidated_aliases(self):
        import subprocess
        import tempfile
        import zipfile
        from unittest.mock import patch
        spec = importlib.util.spec_from_file_location('checker', ROOT / 'scripts/check-community-package.py')
        checker = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(checker)
        sources = (
            b'const {__:tr}=wp.i18n; tr("label","subscription");',
            b'let i=wp.i18n; i=wp.i18n; i.__("label","subscription");',
            b'import {__ as tr} from "@wordpress/i18n"; tr=business; tr("business","subscription");',
            b'const tr=wp.i18n.__; tr("label","subscription");',
            b'const i=wp.i18n; const j=i; j.__("label","subscription");',
            b'let i=wp.i18n; for(i of [business]){} i.__("business","subscription");',
            b'for(let wp of things) { wp.i18n.__("business","subscription"); } wp.i18n.__("label","subscription");',
            b'const tr=(0,wp.i18n.__); tr("label","subscription");',
        )
        (ROOT / 'dist').mkdir(exist_ok=True)
        for source in sources:
            with self.subTest(source=source), tempfile.TemporaryDirectory(dir=ROOT / 'dist') as temporary:
                archive = Path(temporary) / 'fixture.zip'
                with zipfile.ZipFile(archive, 'w') as output:
                    output.writestr('ashbi-subscriptions/ashbi-subscriptions.php',
                                    b'<?php // Text Domain: ashbi-subscriptions\n')
                    output.writestr('ashbi-subscriptions/fixture.js', source)
                # Isolate the real checker scan from completeness/exact-transform gates;
                # these fabricated archives are NOT valid build evidence.
                real_run = checker.run
                def scan_only(command):
                    if '-l' in command:
                        return real_run(command)
                    self.fail('Checker accepted legacy alias and advanced beyond the domain scan')
                with patch.object(checker.builder, 'validate_canonical'), patch.object(checker.builder, 'verify_members'), patch.object(checker, 'run', side_effect=scan_only):
                    with self.assertRaises((subprocess.CalledProcessError, ValueError)):
                        checker.check(archive, archive)

    def test_javascript_alias_redeclaration_and_pattern_writes_fail_closed(self):
        import subprocess
        for source in (
            b'var i=wp.i18n; var i=business; i.__("business","subscription");',
            b'var i=wp.i18n; function i() {} i.__("business","subscription");',
            b'const i=wp.i18n; ({__: i.__}=business); i.__("business","subscription");',
            b'let i=wp.i18n; ({i}=business); i.__("business","subscription");',
        ):
            with self.subTest(source=source):
                with self.assertRaises(subprocess.CalledProcessError) as failure:
                    self.builder.domain_transform('sample.js', source)
                self.assertEqual(b'', failure.exception.stdout, 'Reject before emitting edits')
                self.assertIn(b'translation', failure.exception.stderr)

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
