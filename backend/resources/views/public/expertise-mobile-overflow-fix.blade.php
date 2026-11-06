<style>
/* WEB-03 mobile containment only: preserve the owner-approved artwork and desktop layout. */
@media (max-width: 700px) {
    body:has(.expertise-screen) .expertise-hero {
        min-width: 0;
    }

    body:has(.expertise-screen) .expertise-art {
        width: 100%;
        max-width: 100%;
        min-width: 0;
        box-sizing: border-box;
        justify-self: stretch;
        overflow: clip;
    }

    body:has(.expertise-screen) .expertise-art svg {
        display: block;
        max-width: 100%;
        overflow: clip;
    }
}
</style>
