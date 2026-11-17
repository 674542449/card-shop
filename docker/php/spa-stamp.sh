#!/bin/sh
# Prints a hash of every input that affects the built admin SPA bundle.
#
# public/admin-assets/ is committed to the repository so that deploying needs no Node
# toolchain. The cost of that is a bundle which can silently fall behind the source it
# was built from — edit a page component, forget to rebuild, and the deploy quietly
# ships the old JavaScript while every backend check passes.
#
# So the build writes this hash to public/admin-assets/.build-stamp, and the container
# entrypoint recomputes it at boot and compares. A mismatch means the committed bundle
# is stale.
#
# `tr -d '\r'` normalises line endings, so a checkout on Windows and one on Linux agree.
#
# After running `npm run build`, refresh the stamp with:
#     sh docker/php/spa-stamp.sh > public/admin-assets/.build-stamp
set -e

cd "$(dirname "$0")/../.."

find admin-frontend/src \
     admin-frontend/index.html \
     admin-frontend/vite.config.js \
     admin-frontend/package.json \
     admin-frontend/package-lock.json \
     -type f 2>/dev/null \
  | LC_ALL=C sort \
  | while IFS= read -r f; do
        printf '%s ' "$f"
        tr -d '\r' < "$f" | md5sum | awk '{ print $1 }'
    done \
  | md5sum \
  | awk '{ print $1 }'
