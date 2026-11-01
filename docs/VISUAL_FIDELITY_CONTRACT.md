# ADR-010 — Exact visual fidelity to the supplied 40 reference screens

**Status:** Mandatory owner directive, 2026-11-01. **Authority:** [Program Issue #1](https://github.com/walidatiyaai2025-gif/WalidSite/issues/1). **Applies:** website, Admin and Flutter; W2, W3, LEAD and T024 QA. **Priority:** P0 visual acceptance, without weakening mandatory security, truthfulness, accessibility or functioning behavior.

## Decision: implement the actual attached designs, not inspiration

The 40 original PNGs under `assets/screen-reference/` linked by `contracts/screens.json` are **binding, approved visual targets**. Developers MUST faithfully reproduce the **particular referenced composition**, not build an alternative based on its themes or ideas. This includes exact layout hierarchy, alignment, grid, spacing, proportions, component order/count, typography and Tajawal/Inter fonts, colors, borders, radii, supplied imagery, iconography, shadows and navigation. Use the fixed `design/tokens.json` and approved local assets.

**Never replace the referenced design with a generic template, artistic reinterpretation, simplification or "reference-inspired" layout.** Use only the approved real photograph for the owner's likeness.

The original immutable `contracts/screens.json` has historical boilerplate stating the mockup is "only as intent, not as pixel-perfect app screenshot." **The owner's later explicit instruction supersedes that phrase for visual implementation requirements only.** The mockup is still not proof of running code, real figures, approved content or deployed functionality. Never edit the checksum-protected v6 source files or `MANIFEST.json` to resolve this versioned directive.

## Mandatory per-screen workflow

1. Look up screen ID, route, permission, components, actions and exact `reference_image` in `contracts/screens.json`. Open and inspect that specific PNG at native dimensions; read v6 masterplan, design tokens, approved illustrations and AR/EN content before coding. Do not implement an unseen screen by guessing.
2. Match the actual screenshot's hierarchy, typography, header/sidebar/hero/cards/controls, whitespace, alignment, colors and visual proportions. Preserve approved desktop/mobile layouts, legitimate functionality, real destinations and states. Never insert fictional metrics, credentials, projects, GitHub statistics or AI results to fit a mockup.
3. For visual QA, render the *actual running* Laravel or Flutter screen at exactly the reference PNG's native pixel width/height with controlled font/rendering environment, DPR and a reproducible, non-fabricated data fixture. Capture a real runtime PNG. **Compare every decoded RGBA pixel to its immutable reference: zero unapproved mismatched pixels.** Do not crop, resize, blur, mask large areas or alter the original target to force an artificial pass.
4. Independently test responsiveness at other widths, real data and line-length variation, both languages and RTL/LTR, accessibility WCAG 2.2 AA and keyboard/focus/tap targets, and loading/empty/error/offline/auth states. The baseline screenshot governs the shown viewport; on other devices preserve its design system without breaking functional layout.
5. Store per-screen real screenshot evidence under `qa/visual-captures/`, tested Git SHA, route, locale, viewport/DPR, render command and exact pixel-comparison result. Change that screen's entry in `design/visual-fidelity-evidence.json` from `pending` to `matched` **only after all evidence exists and automated pixel comparison succeeds**. Neither a design-reference PNG nor a mock screenshot may be submitted as runtime proof.
6. The existing `required-ci-gate` verifies all 40 reference mappings and each claimed `matched` screen's capture pixel-for-pixel. CI green while screens remain `pending` means only that evidence accounting is valid; it is NOT a claim that all screens match.
7. Treat unresolved visual differences as engineering defects. Repeat the implementation and comparison without interrupting development for routine owner reviews. Consolidate final owner verification only at T027 when the website and app are ready.

## Reality, security and necessary exceptions

"100% exactly" is the **non-discretionary implementation target**, and a strict zero-pixel-difference acceptance rule at the reproducible reference viewport. It is **not** a claim that every browser, phone, OS/font renderer, language or live-data value will generate identical pixels everywhere.

- Illustrated values and fake buttons in original mockups are NOT authorized data: use actual verified API content or a truthful, accessible empty/disabled state without redesigning the surrounding composition.
- Where literal mockup pixels conflict with security, privacy, accessibility or correct RTL text, document the smallest necessary exception with affected screen ID, side-by-side screenshots and exact reason. Never weaken protective behavior just to match pixels. **Do not mark a screen `matched` if a pixel-level exception remains unresolved**.
- Approved reference PNGs are immutable. New owner-approved replacements, if any, require a separately documented versioned amendment.
- Pin fonts, browser/emulator, language, fixtures and image scale for deterministic comparisons. Real dynamic content and responsive layouts are validated separately, not fabricated for screenshot tests.
- No claims of visual completion without actual captured proof. No fabricated test results, percentage match or external staging screenshots.

## Verifiable acceptance mechanism

- `design/visual-fidelity-evidence.json` lists all 40 references, with initial `pending` status. Each matched item needs an actual independently captured PNG and metadata.
- `qa/validate_visual_fidelity.py` checks PNG structure, canonical coverage, reference paths, evidence, dimensions and **zero pixel differences** in decoded RGBA pixels via Pillow.
- Run `python3 qa/validate_visual_fidelity.py` (install pinned Pillow from the CI specification when executing visual comparisons).
- The same validation is mandatory inside existing `.github/workflows/ci.yml`; the two-workflow policy remains unchanged.
- Example *shape only*, NOT proof of a result: `{"status":"matched","capture":"qa/visual-captures/WEB-01.png","renderer_commit":"<real full 40-hex SHA>","capture_command":"<real repeatable render command>","route":"/","locale":"en","device_pixel_ratio":1}`. Capture width/height come from the immutable reference file. No placeholders can be submitted as verified evidence.

**Stop rule:** A screen with `pending` evidence may have functional code, but MUST NOT be described as an exact visual match or counted as fully visually accepted. T024 requires real screenshot regression proof; T025–T027 still require real deployment, release and owner acceptance evidence.
