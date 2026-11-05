#!/bin/sh
case "$1" in
  *[Uu]sername*)
    printf '%s\n' "x-access-token"
    ;;
  *)
    printf '%s\n' "${DANACO_GITHUB_TOKEN:-}"
    ;;
esac
