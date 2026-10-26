#!/bin/sh
# Builds the deploy package of the wisso app from a COMMIT (default HEAD), never from the working tree.
# Contains only appinfo/ and lib/ (no tests, no tools, no .git files). Deterministic: the same commit gives the same bytes,
# so the sha256 printed here is the one the D-030 gate names and the owner compares on the server.
#
#   sh tools/build-package.sh [commit] [output-dir]      default: HEAD, ../dist
set -eu
cd "$(dirname "$0")/.."
commit="${1:-HEAD}"
out="${2:-../dist}"
full="$(git rev-parse --verify "$commit^{commit}")"
short="$(git rev-parse --short=10 "$full")"
if [ -n "$(git status --porcelain)" ]; then
	echo "working tree is not clean: commit first, the package comes from a commit" >&2
	exit 2
fi
version="$(git show "$full:appinfo/info.xml" | sed -n 's:.*<version>\(.*\)</version>.*:\1:p' | head -n 1)"
mkdir -p "$out"
name="wisso-$version-$short.tar.gz"
# --prefix makes the archive extract to wisso/ ; gzip -n leaves out the file name and time
git archive --format=tar --prefix=wisso/ "$full" appinfo lib | gzip -n -9 > "$out/$name"
echo "commit   $full"
echo "package  $out/$name"
echo "bytes    $(wc -c < "$out/$name" | tr -d ' ')"
echo "sha256   $(sha256sum "$out/$name" | cut -d' ' -f1)"
echo "files    $(tar -tzf "$out/$name" | grep -vc '/$')"
