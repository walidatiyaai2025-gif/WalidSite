<?php

// Explicit opt-in public repositories only. Separate owner/repo values with commas.
// No GitHub token is used: this service cannot access private GitHub data.
return [
    'github_public_repos' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('GITHUB_PUBLIC_REPOS', ''))),
        static fn (string $repo): bool => $repo !== ''
    )),
];
