/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ["./webapp-php/**/*.php"],
  theme: {
    fontFamily: {
      display: ["Fraunces", "Georgia", "serif"],
      sans: ["'DM Sans'", "system-ui", "-apple-system", "sans-serif"],
      mono: ["'DM Mono'", "ui-monospace", "SFMono-Regular", "monospace"],
    },
    extend: {
      colors: {
        parchment: "#F5F0E8",
        "parchment-dark": "#EDE7D9",
        ink: "#1C1917",
        // Text tones darkened to clear WCAG AA (4.5:1) on parchment; the
        // previous #78716C / #A8A29E pair measured 4.23 and 2.22.
        "ink-muted": "#625C56",
        "ink-faint": "#6F6963",
        gov: "#1B3A6B",
        "gov-light": "#EEF2FF",
        // Hairline dividers (decorative, exempt from contrast minimums)…
        "border-warm": "#D6CFC4",
        // …and form-control borders, which need 3:1 under WCAG 1.4.11.
        "border-input": "#8F877D",
      },
    },
  },
  plugins: [],
};
