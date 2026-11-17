# Fonts

This folder holds the local font files for `fonts.css`. They supply Inter (sans-serif) and Fragment Mono (monospace) to every project that installs the DS.
Copy `fonts.css` and this folder together, from one tag. `fonts.css` finds the files with relative URLs (`url("fonts/…")`), so this folder stays next to `fonts.css`.
The install procedure is in the `README.md` at the root of the DS repository, under *Install from an exact tag*.

## Files

Every file is a WOFF2 file, copied byte for byte from the npm package named in *Sources*. The DS does not edit, subset or convert them.
The SHA-256 and the size are of the file in this folder. `tests/fonts.test.mjs` compares each row with the file and fails if they differ.

| File | Family and subset | Weights | Bytes | SHA-256 |
|---|---|---|---|---|
| `inter-latin-wght-normal.woff2` | Inter, latin | 100 to 900 (variable) | 48,256 | `3100e775e8616cd2611beecfa23a4263d7037586789b43f035236a2e6fbd4c62` |
| `inter-latin-ext-wght-normal.woff2` | Inter, latin-ext | 100 to 900 (variable) | 85,068 | `34b9c504cab7a73e37b746343a449132e56cf7b5481af2cb81dc74dcff25c956` |
| `fragment-mono-latin-400-normal.woff2` | Fragment Mono, latin | 400 | 25,224 | `44c4e39bff5e76652a24a872cbebabccbcfb20f62c4633b27c1f2745cba86b56` |
| `fragment-mono-latin-ext-400-normal.woff2` | Fragment Mono, latin-ext | 400 | 14,112 | `54f55ef4bf2e5789b18f862e037e4eb28920048f1d8e7892c090fda9b698312d` |

The four font files are 172,660 bytes in total. A page downloads a file only when it uses a character from its `unicode-range`:
a page with English text needs the two *latin* files (73,480 bytes).
A page with Romanian text also needs `inter-latin-ext-wght-normal.woff2`, because ă, ș and ț are in latin-ext: it downloads 158,548 bytes, plus 14,112 bytes when its monospace text has latin-ext letters.
`fonts.css` carries the same `unicode-range` values as the npm packages (the Google Fonts subsets).

## Licences

Both families use the SIL Open Font License 1.1 (OFL-1.1). The OFL requires the copyright notice and the licence text to travel with the fonts.
Copy this whole folder, so that the notices stay with the files. Do not remove the two licence files.

| File | Holder in the notice | SHA-256 |
|---|---|---|
| [`LICENSE-inter.txt`](LICENSE-inter.txt) | Copyright 2016 The Inter Project Authors (https://github.com/rsms/inter) | `3b0a5fca3d17942cde889069889dedbbbd075e9b599968c82a95f4d944e9b345` |
| [`LICENSE-fragment-mono.txt`](LICENSE-fragment-mono.txt) | Copyright 2022 The Fragment-Mono Project Authors (https://github.com/weiweihuanghuang/fragment-mono) | `d5e728d99896c101da6fe5bdffcdc8cf2618523643b99bd4e9190075f0a0c22e` |

Each licence file is the `LICENSE` file of the npm package, unchanged. Neither notice declares a Reserved Font Name.

## Sources

Each file comes from a pinned Fontsource package on the npm registry. Fontsource republishes the Google Fonts builds of the families.

| Family | Upstream font version (from the `name` table of the files) | npm package | Version | Tarball | npm integrity (SHA-512) | Tarball SHA-256 |
|---|---|---|---|---|---|---|
| Inter | `Version 4.001;git-66647c0bb` | `@fontsource-variable/inter` | 5.2.8 | https://registry.npmjs.org/@fontsource-variable/inter/-/inter-5.2.8.tgz | `sha512-kOfP2D+ykbcX/P3IFnokOhVRNoTozo5/JxhAIVYLpea/UBmCQ/YWPBfWIDuBImXX/15KH+eKh4xpEUyS2sQQGQ==` | `de400e4154a9cbd8ecdaf99183b39a1277a217d967472bfa8b33af1dafc324a7` |
| Fragment Mono | `Version 1.011; ttfautohint (v1.8.4.7-5d5b)` | `@fontsource/fragment-mono` | 5.2.8 | https://registry.npmjs.org/@fontsource/fragment-mono/-/fragment-mono-5.2.8.tgz | `sha512-m+aRQ+c9GghoUtEdSFIPM/ylCxDhY5ESeVzuvF+eSCqkR85VSCWM7jnNWZ+OrC9K2+xp6f0uallDDAjmPLQNrQ==` | `6b32f462e977971e15af069abb6129dfb2479e89f5535e0d94073203ff6fab84` |

- Inter files: `package/files/inter-latin-wght-normal.woff2` and `package/files/inter-latin-ext-wght-normal.woff2`. They are the `wght` builds (one weight axis, 100 to 900; the optical-size axis is fixed). The italic builds are not shipped.
- Fragment Mono files: `package/files/fragment-mono-latin-400-normal.woff2` and `package/files/fragment-mono-latin-ext-400-normal.woff2`. The `.woff` copies and the italic builds in the package are not shipped.
- Upstream projects: https://github.com/rsms/inter and https://github.com/weiweihuanghuang/fragment-mono. Fontsource lists both as Google Fonts builds (https://github.com/google/fonts).
- The stiri report (`docs/compat-analysis.md`) names the same Fontsource version (5.2.8) and the same subsets. The DS did not compare its files with the stiri files byte for byte.

To reproduce the files, work in a new, empty directory:

```sh
npm pack @fontsource-variable/inter@5.2.8 @fontsource/fragment-mono@5.2.8
shasum -a 256 fontsource-*.tgz
mkdir inter mono
tar -xzf fontsource-variable-inter-5.2.8.tgz -C inter
tar -xzf fontsource-fragment-mono-5.2.8.tgz -C mono
shasum -a 256 inter/package/files/inter-latin-wght-normal.woff2 inter/package/files/inter-latin-ext-wght-normal.woff2 \
  mono/package/files/fragment-mono-latin-400-normal.woff2 mono/package/files/fragment-mono-latin-ext-400-normal.woff2
```

`npm view <package>@5.2.8 dist.integrity` prints the integrity value of the registry. The tarball SHA-256 above is of the tarball that `npm pack` wrote when the files were selected.

## What the files cover

Measured from the files, not from documentation (`tests/fonts.test.mjs` reads the `cmap` table of each WOFF2 file and the `fvar` table of the Inter files):

- Romanian letters Ă ă Â â Î î Ș ș Ț ț (comma-below forms U+0218 to U+021B included) are in both families. `fonts.css` sends each of them to the file that contains it.
  Inter has the old cedilla forms Ş ş Ţ ţ too. Fragment Mono has Ş ş but not Ţ ţ (U+0162, U+0163). Text with Ţ ţ in a monospace role falls back to the next monospace font for those two letters.
- Inter has one weight axis from 100 to 900. The DS names the weights 400 (body), 500 (chip, wordmark domain), 600 (buttons, tags, labels, navigation) and 700 (headings, wordmark, error text). The other weights stay available to project CSS but the DS does not use them.
- Fragment Mono has the weight 400 only. A rule that sets `font-weight: 700` on monospace text gets a synthetic bold from the browser. The DS sets no weight on monospace text.
- The DS uses no italic. No italic file is shipped.

### Why one variable Inter file, not four static files

The DS uses four Inter weights. The sizes below are the exact sizes of the npm files, measured on 2026-10-26:

| Option | Files | Bytes |
|---|---|---|
| Shipped: Inter variable (`wght`), latin and latin-ext | 2 | 133,324 |
| Four static weights (400, 500, 600, 700), latin and latin-ext, `@fontsource/inter` 5.2.8 | 8 | 240,272 |
| Inter variable, latin only (no ă ș ț) | 1 | 48,256 |

The variable files are 44.5% smaller than the four static weights, cover every weight in 100 to 900 and need two requests, not eight. Latin only does not cover Romanian, so it is not an option.
The cost: browsers that do not support variable fonts are not supported by `fonts.css`. Every browser that supports WOFF2 and CSS cascade layers (which `gov2.css` needs) supports variable fonts.

## Load order and the Content-Security-Policy

- Link `fonts.css` anywhere in `<head>`. `fonts.css` declares `@font-face` rules and nothing else, so its order against `gov2.css` does not matter. It uses no cascade layer.
- `gov2.css` keeps the family tokens `--g2-font-sans` and `--g2-font-mono`. They name `"Inter"` and `"Fragment Mono"`, then system fonts. `fonts.css` declares those two family names.
- Every face has `font-display: swap`: the browser shows the system font at once and swaps to the web font when the file arrives. A page with `fonts.css` and blocked font files stays readable.
- A page that links `fonts.css` needs `font-src 'self'` in its Content-Security-Policy, when the page, `fonts.css` and `fonts/` are on the same origin. It also needs `style-src 'self'` for the two stylesheets. It needs no `data:` source.
  A page without `fonts.css` needs no `font-src` for the DS.
- If you serve `fonts/` from another origin than the page, that server must send `Access-Control-Allow-Origin` for the page origin, and `font-src` must list that origin. Browsers fetch fonts with CORS. This case is not tested: no CDN or second origin is configured.

## Update the fonts

Only the DS maintainer changes this folder. To use a newer package, copy the new files, update every row and checksum above, and run `npm test`. The tests fail until the table matches the files.
A project with a copy of the DS keeps its fonts until it updates to a newer tag.
