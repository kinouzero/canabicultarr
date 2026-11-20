"""Plan an immutable, CI-gated release without exposing registry credentials."""
import json
import os
from pathlib import Path
import re
import sys
from urllib.error import HTTPError
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[1]
VERSION = re.compile(r'(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-(alpha|beta|rc)\.(0|[1-9]\d*))?', re.ASCII)


DEFAULT_GHCR = 'ghcr.io/kinouzero/canabicultarr'
COMPOSE_APP_IMAGE = re.compile(r'^    image: \$\{APP_IMAGE:-[^}\r\n]+\}$', re.MULTILINE)


def compose_with_image(contents, version, ghcr=DEFAULT_GHCR):
    if not VERSION.fullmatch(version):
        raise ValueError('Invalid application version.')
    if len(COMPOSE_APP_IMAGE.findall(contents)) != 1:
        raise ValueError('Compose must define exactly one APP_IMAGE default.')
    return COMPOSE_APP_IMAGE.sub(f'    image: ${{APP_IMAGE:-{ghcr}:{version}}}', contents)


def release_plan(version, repository, username, sha, requested_tag, get):
    match = VERSION.fullmatch(version)
    if not match or not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', repository):
        raise ValueError('Invalid release version or repository.')
    if not re.fullmatch(r'[a-z0-9][a-z0-9_.-]{1,62}', username):
        raise ValueError('Configure a valid Docker Hub username.')
    if not re.fullmatch(r'[a-f0-9]{40}', sha):
        raise ValueError('A verified commit SHA is required.')
    tag = 'v' + version
    if requested_tag != tag:
        raise ValueError('Release tag must match frontend/package.json exactly.')
    release = get(f'repos/{repository}/releases/tags/{tag}')
    if release and release.get('draft'):
        raise ValueError('An existing draft requires review before publication.')
    published = release is not None
    if not published:
        reference = get(f'repos/{repository}/git/ref/tags/{tag}')
        if not reference:
            raise ValueError('An existing pushed version tag is required.')
        obj = reference.get('object', {})
        for _ in range(5):
            if obj.get('type') != 'tag':
                break
            annotated = get(f"repos/{repository}/git/tags/{obj['sha']}")
            obj = (annotated or {}).get('object', {})
        if obj.get('type') != 'commit' or obj.get('sha') != sha:
            raise ValueError('The version tag does not point to the tested commit.')
    channel = match.group(4) or 'latest'
    ghcr = 'ghcr.io/' + repository.lower()
    hub = username + '/' + repository.split('/')[1].lower()
    return {'publish': str(not published).lower(), 'version': version, 'tag': tag,
            'prerelease': str(channel != 'latest').lower(), 'ghcr': ghcr, 'hub': hub,
            'image_tags': '\n'.join(f'{image}:{label}' for image in (ghcr, hub)
                                   for label in (version, channel, 'sha-' + sha[:12]))}


def github_get(path):
    request = Request('https://api.github.com/' + path, headers={
        'Authorization': 'Bearer ' + os.environ['GH_TOKEN'],
        'Accept': 'application/vnd.github+json',
        'X-GitHub-Api-Version': '2022-11-28',
    })
    try:
        with urlopen(request, timeout=15) as response:
            result = json.loads(response.read(1024 * 1024))
            if not isinstance(result, dict):
                raise ValueError('Unexpected GitHub API response.')
            return result
    except HTTPError as error:
        if error.code == 404:
            return None
        raise RuntimeError(f'GitHub release preflight failed (HTTP {error.code}).') from None


if __name__ == '__main__':
    version = json.loads((ROOT / 'frontend/package.json').read_text())['version']
    if sys.argv[1:] == ['--check-tag']:
        if not VERSION.fullmatch(version) or os.environ.get('RELEASE_TAG') != 'v' + version:
            sys.exit('Release tag must match frontend/package.json exactly.')
        print(version)
        sys.exit()
    compose = ROOT / 'compose.yaml'
    contents = compose.read_text()
    if sys.argv[1:] == ['--sync-compose']:
        updated = compose_with_image(contents, version)
        if updated != contents:
            compose.write_text(updated)
        sys.exit()
    if len(sys.argv) != 1:
        sys.exit('Usage: python3 scripts/release-metadata.py [--sync-compose | --check-tag]')
    ghcr = 'ghcr.io/' + os.environ['GITHUB_REPOSITORY'].lower()
    if compose_with_image(contents, version, ghcr) != contents:
        sys.exit('Compose image does not match the release. Run python3 scripts/release-metadata.py --sync-compose before tagging.')
    plan = release_plan(version, os.environ['GITHUB_REPOSITORY'],
                        os.environ['DOCKERHUB_USERNAME'].strip().lower(),
                        os.environ['RELEASE_SHA'], os.environ['RELEASE_TAG'], github_get)
    with open(os.environ['GITHUB_OUTPUT'], 'a', encoding='utf-8') as output:
        for name, value in plan.items():
            if name == 'image_tags':
                output.write(f'{name}<<RELEASE_IMAGE_TAGS\n{value}\nRELEASE_IMAGE_TAGS\n')
            else:
                output.write(f'{name}={value}\n')
    print('Release preflight:', plan['tag'], 'publish=' + plan['publish'])
