"""Validate release boundaries without publishing or making network requests."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest

spec = importlib.util.spec_from_file_location('release_metadata', Path(__file__).parents[1] / 'release-metadata.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
SHA = 'a' * 40


class ReleaseMetadataTest(unittest.TestCase):
    def plan(self, version='0.1.0', get=None, tag=None, republish_sha=''):
        return release.release_plan(version, 'Owner/Canabicultarr', 'example', SHA, tag or 'v' + version,
                                    get or (lambda path: None if '/releases/' in path else {'object': {'type': 'commit', 'sha': SHA}}),
                                    republish_sha=republish_sha)

    def test_prereleases_publish_version_channel_and_commit_tags_without_latest(self):
        plan = self.plan('1.2.3-rc.1')
        self.assertEqual(plan['publish'], 'true')
        self.assertEqual(plan['tag'], 'v1.2.3-rc.1')
        self.assertEqual(plan['prerelease'], 'true')
        self.assertEqual(len(plan['image_tags'].splitlines()), 6)
        self.assertIn('ghcr.io/owner/canabicultarr:rc', plan['image_tags'])
        self.assertNotIn(':latest', plan['image_tags'])
        self.assertIn('example/canabicultarr:1.2.3-rc.1', plan['image_tags'])

    def test_stable_releases_get_latest(self):
        plan = self.plan()
        self.assertEqual(plan['tag'], 'v0.1.0')
        self.assertEqual(plan['prerelease'], 'false')
        self.assertIn(':latest', plan['image_tags'])

    def test_compose_pins_the_exact_manifest_version(self):
        root = release.ROOT
        contents = (root / 'compose.yaml').read_text()
        version = json.loads((root / 'frontend/package.json').read_text())['version']
        self.assertEqual(release.compose_with_image(contents, version), contents)
        new_version = '2.3.4-rc.1'
        updated = release.compose_with_image(contents, new_version)
        self.assertIn(f'image: ${{APP_IMAGE:-{release.DEFAULT_GHCR}:{new_version}}}', updated)
        self.assertEqual(release.compose_with_image(updated, version), contents)

    def test_installer_preflight_reports_only_the_manifest_version_for_the_matching_tag(self):
        version = json.loads((release.ROOT / 'frontend/package.json').read_text())['version']
        command = [sys.executable, str(release.ROOT / 'scripts/release-metadata.py'), '--check-tag']
        result = subprocess.run(command, capture_output=True, text=True, env={**os.environ, 'RELEASE_TAG': 'v' + version})
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, version + '\n')
        result = subprocess.run(command, capture_output=True, text=True, env={**os.environ, 'RELEASE_TAG': 'v' + version + '.1'})
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, '')

    def test_published_versions_require_approval_for_the_tested_commit(self):
        def existing(path):
            return {'draft': False} if '/releases/' in path else {'object': {'type': 'commit', 'sha': SHA}}

        self.assertEqual(self.plan(get=existing)['publish'], 'false')
        self.assertEqual(self.plan(get=existing, republish_sha='b' * 40)['publish'], 'false')
        plan = self.plan(get=existing, republish_sha=SHA)
        self.assertEqual(plan['publish'], 'true')
        self.assertEqual(plan['republish'], 'true')
        self.assertEqual(self.plan(republish_sha=SHA)['republish'], 'false')

    def test_republication_refuses_immutable_releases_and_mismatched_tags(self):
        with self.assertRaisesRegex(ValueError, 'immutable'):
            self.plan(get=lambda path: {'draft': False, 'immutable': True}, republish_sha=SHA)
        with self.assertRaisesRegex(ValueError, 'does not point'):
            self.plan(get=lambda path: {'draft': False} if '/releases/' in path else {'object': {'type': 'commit', 'sha': 'b' * 40}},
                      republish_sha=SHA)

    def test_existing_drafts_and_conflicting_tags_fail_closed(self):
        with self.assertRaises(ValueError):
            self.plan(get=lambda path: {'draft': True})
        with self.assertRaises(ValueError):
            self.plan(get=lambda path: None if '/releases/' in path else {'object': {'type': 'commit', 'sha': 'b' * 40}})
        plan = self.plan(get=lambda path: None if '/releases/' in path else {'object': {'type': 'commit', 'sha': SHA, 'url': 'ignored'}})
        self.assertEqual(plan['publish'], 'true')

    def test_tag_must_exist_match_the_manifest_and_resolve_to_the_tested_commit(self):
        with self.assertRaises(ValueError):
            self.plan(tag='v9.0.0')
        with self.assertRaises(ValueError):
            self.plan(get=lambda path: None)
        def annotated(path):
            if '/releases/' in path:
                return None
            return {'object': {'type': 'commit', 'sha': SHA}} if '/git/tags/' in path else {'object': {'type': 'tag', 'sha': 'b' * 40}}
        self.assertEqual(self.plan(get=annotated)['publish'], 'true')

    def test_untrusted_version_or_registry_metadata_is_rejected(self):
        for version in ['$(command)', '1.0', '01.0.0', '1.0.0-alpha.١', '1.0.0-alpha.01', '1.0.0\nINJECTED=value']:
            with self.subTest(version=version), self.assertRaises(ValueError):
                self.plan(version)
        with self.assertRaises(ValueError):
            release.release_plan('1.0.0', 'owner/repo', 'bad\nname', SHA, 'v1.0.0', lambda path: None)
        with self.assertRaises(ValueError):
            release.release_plan('1.0.0', 'owner/repo', 'example', 'master', 'v1.0.0', lambda path: None)


if __name__ == '__main__':
    unittest.main()
