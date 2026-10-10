"""Offline release discovery regression tests; no GitHub access or WordPress required."""
import importlib.util
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('publisher', Path(__file__).resolve().parents[1] / 'scripts/publish-catalogue.py')
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)


class DiscoveryTests(unittest.TestCase):
    def package(self, author=None, uri=None, extra='', filename='new-plugin/main.php', asset='new-plugin.zip'):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        path = Path(temp.name) / 'package.zip'
        text = '<?php\n/*\nPlugin Name: New Capability\nVersion: 1.2.3\nAuthor: ' + (author or p.BRAND) + '\n'
        if uri is not None:
            text += 'Update URI: ' + uri + '\n'
        with zipfile.ZipFile(path, 'w') as z:
            z.writestr(filename, text + extra + '\n*/')
        return path, asset

    def inspect(self, **kwargs):
        path, asset = self.package(**kwargs)
        return p.inspect_package(path, 'unprefixed-repository', asset, {'tag_name': 'v1.2.3'})

    def test_unregistered_unprefixed_release(self):
        entry = self.inspect(uri='https://github.com/cchatterton/unprefixed-repository')
        self.assertEqual(entry['file'], 'new-plugin/main.php')
        self.assertEqual(entry['author'], p.BRAND)
        self.assertTrue(entry['beta'])
        self.assertEqual(len(entry['sha256']), 64)

    def test_exclusive_policy(self):
        self.assertFalse(self.inspect()['exclusive'])
        path, asset = self.package()
        entry = p.inspect_package(path, 'unprefixed-repository', asset, {'tag_name': 'v1.2.3'}, {'exclusive': True})
        self.assertTrue(entry['exclusive'])
        self.assertEqual(entry['allowed_domains'], [])
        for value in ['true', 1, None, []]:
            with self.assertRaises(ValueError):
                p.inspect_package(path, 'unprefixed-repository', asset, {'tag_name': 'v1.2.3'}, {'exclusive': value})

    def test_other_brand_and_similar_authors_excluded(self):
        for author in ['Techn' if p.BRAND == 'AlphaSys' else 'AlphaSys', p.BRAND + ' Partners', 'Someone Else']:
            self.assertIsNone(self.inspect(author=author))

    def test_owner_and_update_uri_must_agree(self):
        with self.assertRaises(ValueError):
            self.inspect(uri='https://github.com/untrusted/unprefixed-repository')

    def test_unsafe_path_rejected(self):
        with self.assertRaises(ValueError):
            self.inspect(filename='../main.php')

    def test_domains_from_package(self):
        entry = self.inspect(extra='Allowed Domains: example.org\nAllow Subdomains: true')
        self.assertEqual(entry['allowed_domains'], ['example.org'])
        self.assertTrue(entry['include_subdomains'])
        with self.assertRaises(ValueError):
            self.inspect(extra='Allowed Domains: https://example.org')

    def test_blank_header_does_not_consume_next_header(self):
        self.assertEqual(p.header('Description:\nAuthor: Techn', 'Description'), '')

    def test_ambiguous_author_exception(self):
        path, asset = self.package(author='Original Author')
        result = p.inspect_package(path, 'legacy', asset, {'tag_name': 'v1.2.3'}, {'author_header': 'Original Author', 'beta': False})
        self.assertEqual(result['author'], p.BRAND)
        self.assertFalse(result['beta'])

    def test_ambiguous_assets_or_mains_fail(self):
        path, asset = self.package()
        with zipfile.ZipFile(path, 'a') as z:
            z.writestr('new-plugin/another.php', 'Plugin Name: Another\nAuthor: ' + p.BRAND)
        with self.assertRaises(ValueError):
            p.inspect_package(path, 'new', asset, {'tag_name': 'v1.2.3'})

    def test_default_discovery_and_exclusions(self):
        record = self.inspect()
        def repo(name, **kw):
            return dict(name=name, latestRelease={'isDraft': False, 'isPrerelease': False}, **kw)
        repos = [repo('unprefixed-repository'), repo('excluded'), repo('superseded'), repo('fork', isFork=True), dict(name='unreleased', latestRelease=None)]
        calls = []
        def collect(name, exception):
            calls.append(name)
            return record
        result = p.discover(repos, {'excluded': {'exclude': True}, 'superseded': {'superseded_by': 'unprefixed-repository'}}, {}, collect)
        self.assertEqual(calls, ['unprefixed-repository'])
        self.assertEqual(result, [record])

    def test_forks_require_explicit_include(self):
        record = self.inspect()
        repos = [dict(name='unprefixed-repository', isFork=True, latestRelease={ 'isDraft': False })]
        self.assertEqual(p.discover(repos, {'unprefixed-repository': {'include': True}}, {}, lambda *args: record), [record])

    def test_identity_pins_and_missing_previous_release(self):
        record = self.inspect()
        repos = [dict(name='unprefixed-repository', latestRelease={'isDraft': False})]
        old = dict(record, file='new-plugin/previous.php')
        with self.assertRaises(ValueError):
            p.discover(repos, {}, {'plugins': [old]}, lambda *args: record)
        old = dict(record, id='missing', repo='missing')
        with self.assertRaises(ValueError):
            p.discover(repos, {}, {'plugins': [old]}, lambda *args: record)

    def test_repository_pagination_requested(self):
        payload = b'[{"data":{"user":{"repositories":{"nodes":[{"name":"one"}]}}}},{"data":{"user":{"repositories":{"nodes":[{"name":"two"}]}}}}]'
        with patch.object(p, 'gh', return_value=payload) as api:
            self.assertEqual(len(p.repositories()), 2)
            self.assertIn('--paginate', api.call_args.args)

    def test_targeted_release_preserves_other_entries(self):
        record = self.inspect()
        old = dict(record, id='old', repo='old', file='old/old.php', slug='old', asset='old.zip')
        info = {"name": record['repo'], "owner": {"login": p.OWNER}, "private": False, "fork": False, "archived": False}
        calls = []
        def collect(name, rule):
            calls.append(name)
            return record
        import json
        with patch.object(p, 'gh', return_value=json.dumps(info).encode()):
            results = p.discover_one(record['repo'], {}, {'plugins': [old]}, collect)
        self.assertEqual(calls, [record['repo']])
        self.assertIn(old, results)
        self.assertIn(record, results)

    def test_replaced_asset_under_same_tag_rejected(self):
        record = self.inspect()
        old = dict(record, sha256='0' * 64)
        with self.assertRaises(ValueError):
            p.discover([dict(name=record['repo'], latestRelease={'isDraft': False})], {}, {'plugins': [old]}, lambda *args: record)

    def test_targeted_repository_rejects_other_owner(self):
        with patch.object(p, 'gh', return_value=b'{"name":"test", "owner":{"login":"other"},"private":false}'):
            with self.assertRaises(ValueError):
                p.discover_one('test', {}, {})


if __name__ == '__main__':
    unittest.main()
