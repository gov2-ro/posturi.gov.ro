/** @type {import('tailwindcss').Config} */

/*
 * Every colour here resolves to a CSS custom property rather than a literal, so
 * the palette is swappable at runtime by re-declaring variables — that is what
 * makes skins possible (webapp-php/static/skins/, see inc/skins.php). The
 * defaults live in one `:root` block in assets/app.css; a skin overrides the
 * same names under `[data-skin="<id>"]`.
 *
 * Variables hold space-separated RGB channels ("245 240 232", not "#F5F0E8")
 * because that is the only form Tailwind's `<alpha-value>` placeholder can
 * compose with — it is what keeps `bg-surface/70` and `border-note/60` working.
 *
 * Naming rule: tokens name a ROLE, never a colour or a texture. `bg-page`
 * survives being re-skinned; `bg-parchment` (what this used to be) becomes a
 * lie the moment a skin is anything but beige.
 */
const c = (v) => `rgb(var(--c-${v}) / <alpha-value>)`;

module.exports = {
  content: ["./webapp-php/**/*.php"],
  theme: {
    fontFamily: {
      display: "var(--font-display)",
      sans: "var(--font-sans)",
      mono: "var(--font-mono)",
    },
    borderRadius: {
      none: "0",
      DEFAULT: "var(--radius)",
      md: "var(--radius-md)",
      lg: "var(--radius-lg)",
      // Pills and progress-bar caps. A token rather than 9999px so a square
      // skin (govuk) can flatten its tags without also squaring nothing else.
      full: "var(--radius-pill)",
    },
    extend: {
      colors: {
        // Surfaces, back to front.
        page: c("page"),
        sunken: c("sunken"),
        surface: c("surface"),
        line: { DEFAULT: c("line"), strong: c("line-strong") },

        // Text, most to least prominent.
        ink: { DEFAULT: c("ink"), muted: c("ink-muted"), faint: c("ink-faint") },

        // Brand, in two parts. `gov` is the action colour — links, buttons,
        // badges — paired with `on-gov` for text drawn on a gov fill. `gov-bar`
        // is the masthead fill, paired with the `on-bar` ramp.
        //
        // These were one token until both skins turned out to need a masthead
        // that is not their link colour (GOV.UK: black bar, blue links;
        // posturi.gov.ro: navy bar, lighter blue links) and had to override
        // `header` by hand to get it. Splitting them means no skin hardcodes the
        // header, and it makes the on-fill contrast pairs checkable — see
        // assets/check-skins.php.
        gov: { DEFAULT: c("gov"), light: c("gov-light"), bar: c("gov-bar") },
        "on-gov": c("on-gov"),
        "on-bar": {
          DEFAULT: c("on-bar"),
          muted: c("on-bar-muted"),
          accent: c("on-bar-accent"),
        },
        focus: c("focus"),

        // Semantic families. DEFAULT is the fill, so the common case reads
        // `bg-note`; `border-note-line` and `text-note-ink` are the other two.
        info: { DEFAULT: c("info-bg"), line: c("info-line"), ink: c("info-ink") },
        neutral: { DEFAULT: c("neutral-bg"), line: c("neutral-line"), ink: c("neutral-ink") },
        ok: { DEFAULT: c("ok-bg"), line: c("ok-line"), ink: c("ok-ink"), solid: c("ok-solid") },
        note: { DEFAULT: c("note-bg"), line: c("note-line"), ink: c("note-ink") },
        alert: { DEFAULT: c("alert-bg"), line: c("alert-line"), ink: c("alert-ink") },
      },
    },
  },
  plugins: [],
};
