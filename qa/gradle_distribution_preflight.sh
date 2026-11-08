#!/usr/bin/env bash
# Gradle wrapper download gate. Only retry the isolated distribution bootstrap;
# NEVER retry/ignore Flutter compilation, Gradle tasks or APK verification.
set -euo pipefail
if [[ "$#" -ne 0 ]]; then
  echo 'Gradle bootstrap takes no arguments' >&2
  exit 64
fi
if [[ ! -f mobile/android/gradlew || ! -f mobile/android/gradle/wrapper/gradle-wrapper.properties ]]; then
  echo 'Checked-in Gradle wrapper unavailable' >&2
  exit 1
fi
# Run from the exact checked-out repository root. A corrupted/offline Gradle
# download must never fall back to a different version or unverified mirror.
for attempt in 1 2 3; do
  if (cd mobile/android && bash ./gradlew --version); then
    echo 'Verified Gradle wrapper distribution available'
    exit 0
  fi
  if [[ "$attempt" -eq 3 ]]; then
    break
  fi
  echo "Gradle distribution bootstrap failed (attempt $attempt); retrying the same pinned URL" >&2
  sleep $((attempt * 5))
done
echo 'Verified Gradle distribution unavailable after bounded retries' >&2
exit 1
