#!/usr/bin/env bash
# Source this from the trusted CI/release shell *after* flutter pub get.
# Flutter generates android/gradlew as part of flutter build, so invoking the
# wrapper separately before the build is invalid. Retry only proven transient
# network failures for our official, checksum-pinned Gradle 9.1.0 download.
# Compilation, tests, signing, origin and APK acceptance remain fail closed.

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  echo 'Source this build helper rather than executing it directly' >&2
  exit 64
fi

with_gradle_distribution_retry() {
  if [[ "$#" -lt 4 || "$1" != "flutter" || "$2" != "build" || "$3" != "apk" ]]; then
    echo 'Expected the pinned Flutter APK build command' >&2
    return 64
  fi

  local log attempt
  log="$(mktemp)"
  for attempt in 1 2 3; do
    : > "$log"
    if "$@" > "$log" 2>&1; then
      cat "$log"
      rm -f -- "$log"
      return 0
    fi
    cat "$log"
    # Only retry java.io.IOException for the EXACT pinned Gradle distribution
    # over official HTTPS. Never retry a Dart/Java compilation, signing or
    # generic Gradle execution failure, even if an earlier attempt had HTTP 504.
    if ! grep -Eq 'java[.]io[.]IOException: Server returned HTTP response code: (429|500|502|503|504) for URL: https://(github[.]com/gradle/gradle-distributions/releases/download/v9[.]1[.]0/gradle-9[.]1[.]0-bin[.]zip|services[.]gradle[.]org/distributions/gradle-9[.]1[.]0-bin[.]zip)' "$log"; then
      rm -f -- "$log"
      echo 'Android build failed; not an approved Gradle transport retry' >&2
      return 1
    fi
    if [[ "$attempt" -eq 3 ]]; then
      break
    fi
    echo "Pinned Gradle distribution temporarily unavailable; retrying attempt $((attempt + 1))/3" >&2
    sleep $((attempt * 5))
  done
  rm -f -- "$log"
  echo 'Android build blocked after 3 verified Gradle download attempts' >&2
  return 1
}
