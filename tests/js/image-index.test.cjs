const { test } = require('node:test');
const assert = require('node:assert/strict');
const expected = { amd64: 'sha256:' + 'a'.repeat(64), arm64: 'sha256:' + 'b'.repeat(64) };
const fixture = () => ({ schemaVersion: 2, mediaType: 'application/vnd.oci.image.index.v1+json', manifests: Object.entries(expected).map(([architecture, digest]) => ({ digest, platform: { os: 'linux', architecture } })) });
test('both supported platforms retain the exact smoke-tested digests', async () => {
  const { verifyImageIndex } = await import('../../scripts/verify-image-index.mjs');
  verifyImageIndex(fixture(), expected);
  const docker = fixture(); docker.mediaType = 'application/vnd.docker.distribution.manifest.list.v2+json'; docker.manifests[1].platform.variant = 'v8';
  verifyImageIndex(docker, expected);
});
for (const [name, mutate] of [
  ['missing ARM64', x => x.manifests.pop()],
  ['duplicate AMD64', x => x.manifests[1] = x.manifests[0]],
  ['untested image digest', x => x.manifests[1].digest = 'sha256:' + 'c'.repeat(64)],
  ['wrong operating system', x => x.manifests[1].platform.os = 'windows'],
  ['unsupported ARM variant', x => x.manifests[1].platform.variant = 'v7'],
  ['single-platform manifest', x => x.mediaType = 'application/vnd.oci.image.manifest.v1+json'],
]) test(`reject ${name}`, async () => {
  const { verifyImageIndex } = await import('../../scripts/verify-image-index.mjs');
  const index = fixture(); mutate(index); assert.throws(() => verifyImageIndex(index, expected));
});
