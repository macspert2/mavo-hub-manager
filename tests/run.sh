#!/bin/sh
# Runs every test file. Exits non-zero if any assertion fails.
status=0
for file in "$(dirname "$0")"/test-*.php; do
	echo "== $(basename "$file")"
	php "$file" || status=1
	echo
done
exit $status
