# Setup without human intervention – operational boundary
1. LEAD creates repo scaffold, copies canonical contracts, opens issues for task register and assigns one worker each.
2. LEAD configures environment when authorised secrets/existing hosting are available; otherwise deploy local/staging mocks and mark blocked external.
3. Execute independent tasks after dependencies, reusing active branches and PRs.
4. Run exact-head required tests and visual QA then integrate; verify staging publicly only if deployment access granted.
5. Do not require the owner during normal sprint work. Owner provides feedback only after preview is deployed.
6. Domain DNS, signing and app stores cannot be fabricated; no claims of actual deployment without runtime evidence.
