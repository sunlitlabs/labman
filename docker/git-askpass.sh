#!/bin/sh
# Used by git (GIT_ASKPASS) so the access token is passed through the environment of a single
# git process: it is never put in a URL, a command line, the git config or a file.
case "$1" in
    Username*) printf '%s' "$LABMAN_GIT_USERNAME" ;;
    *) printf '%s' "$LABMAN_GIT_PASSWORD" ;;
esac
