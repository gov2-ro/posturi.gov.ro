/** @type {import('tailwindcss').Config} */

/*
 * Every colour here resolves to a CSS custom property rather than a literal. The
 * role tokens (`--c-*`, `--font-*`, `--radius*`) are aliases of the gov2 design
 * system tokens (`--g2-*`): the `:root` block in assets/app.css sets each one
 * (ADR 0005). To change a colour, change the alias, never a template.
 *
 * Variables hold space-separated RGB channels ("29 79 191", not "#1D4FBF")
 * because that is the only form Tailwind's `<alpha-value>` placeholder can
 * compose with — it is what keeps `bg-surface/70` and `border-note/60` working.
 * The DS colour tokens are RGB channels too, so an alias keeps the form.
 *
 * Naming rule: tokens name a ROLE, never a colour or a texture. `bg-page` says
 * what the colour is for; `bg-parchment` (an old name) says what it looked like.
 */
const c = (v) => `rgb(var(--c-${v}) / <alpha-value>)`;

module.exports = {
  content: ["./webapp-php/**/*.php", "./webapp-php/static/saved.js"],
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
      // Pills and progress-bar caps. The DS is square, so this is `--g2-radius` (0) too.
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

        // Brand, in two parts. `gov` is the link colour — links, badges, the
        // selected state — paired with `on-gov` for text drawn on a gov fill.
        // `gov-bar` is the masthead fill, paired with the `on-bar` ramp. The DS
        // has the same split (`--g2-color-link`, `--g2-color-header-bg`).
        gov: { DEFAULT: c("gov"), light: c("gov-light"), bar: c("gov-bar") },
        "on-gov": c("on-gov"),
        "on-bar": {
          DEFAULT: c("on-bar"),
          muted: c("on-bar-muted"),
          accent: c("on-bar-accent"),
        },
        focus: c("focus"),

        // The first series of the chart (`--g2-color-chart-1`).
        chart: c("chart-1"),

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
