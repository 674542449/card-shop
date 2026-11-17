import assert from 'node:assert/strict';
import fs from 'node:fs';
import { pathToFileURL } from 'node:url';

export function verifyImageIndex(index, expected) {
  assert.equal(index.schemaVersion, 2, 'Expected a v2 image index');
  assert.ok(['application/vnd.oci.image.index.v1+json', 'application/vnd.docker.distribution.manifest.list.v2+json'].includes(index.mediaType), 'Expected a multi-platform index');
  assert.deepEqual(Object.keys(expected).sort(), ['amd64', 'arm64']);
  assert.equal(index.manifests?.length, 2, 'Both platforms must be present exactly once');
  const seen = new Set();
  for (const manifest of index.manifests) {
    const platform = manifest.platform;
    assert.equal(platform?.os, 'linux');
    assert.ok(Object.hasOwn(expected, platform.architecture), 'Unexpected architecture');
    assert.ok(!seen.has(platform.architecture), 'Duplicate architecture');
    assert.ok(!platform.variant || (platform.architecture === 'arm64' && platform.variant === 'v8'), 'Unexpected architecture variant');
    assert.match(expected[platform.architecture], /^sha256:[a-f0-9]{64}$/);
    assert.equal(manifest.digest, expected[platform.architecture], 'Published digest differs from the tested image');
    seen.add(platform.architecture);
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const [, , filename, amd64, arm64] = process.argv;
  verifyImageIndex(JSON.parse(fs.readFileSync(filename, 'utf8')), { amd64, arm64 });
  console.log('Verified linux/amd64 and linux/arm64 with the exact tested digests.');
}
