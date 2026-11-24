<?php
/**
 * Design — brand assets, and now the brand faces.
 *
 * The first real code in this module (the readme was a plan; this is its
 * smallest slice). Two faces, chosen in the mono audition
 * (design catalogue /plugin-onboarding-mono, candidate E):
 *
 *   Space Mono       DATA ONLY — ghost numerals, values, version chips.
 *                    A display mono whose quirks are personality at those
 *                    sizes and noise everywhere else, which is why it is
 *                    not the label face.
 *   League Spartan   labels and headings — the title-block voice the DDD
 *                    artifacts established. 600 only.
 *
 * Whispers and body copy stay on the native sans: a sentence is not an
 * identifier.
 *
 * ~30KB total, latin subsets, font-display: swap. Emitted inline by the
 * surfaces that use them (wizard, hub) rather than enqueued globally —
 * other people's admin pages should not pay for our fonts.
 */
namespace tangible\design;

use tangible\framework;

/**
 * The @font-face block, with URLs resolved wherever the framework lives.
 *
 * Every face bundled here must be redistributable under a GPL-compatible
 * licence (Space Mono and League Spartan are OFL). Recoleta is deliberately
 * absent: it is a commercial face licensed for Tangible's own sites only, so
 * it cannot ship inside a plugin ZIP (wordpress.org guideline 1).
 */
function font_faces_css() {
  // module_url carries no trailing slash — without this the URLs read
  // "designfonts/…" and every face 404s to its fallback.
  $base = trailingslashit(framework\module_url(__FILE__)) . 'fonts/';
  return "
    @font-face { font-family:'Space Mono'; font-weight:400; font-style:normal;
      font-display:swap; src:url('{$base}space-mono-400.woff2') format('woff2'); }
    @font-face { font-family:'Space Mono'; font-weight:700; font-style:normal;
      font-display:swap; src:url('{$base}space-mono-700.woff2') format('woff2'); }
    @font-face { font-family:'League Spartan'; font-weight:600; font-style:normal;
      font-display:swap; src:url('{$base}league-spartan-600.woff2') format('woff2'); }
  ";
}

/**
 * The Tangible mark — the six-tile logo in the brand colours.
 *
 * The default lockup for any surface that has no plugin logo of its own.
 */
function logo_url() {
  return set_url_scheme(trailingslashit(framework\module_url(__FILE__)) . 'tangible-logo.svg');
}

/** The three voices, as tokens the surfaces share. */
function font_tokens_css() {
  return "
    --tgbl-font-display: Georgia, serif;
    --tgbl-font-data: 'Space Mono', ui-monospace, Menlo, monospace;
    --tgbl-font-label: 'League Spartan', system-ui, sans-serif;
    --tgbl-font-body: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  ";
}
