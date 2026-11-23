**Type:** component

**Breaking:** no

**From project:** gov2-ro/posturi.gov.ro, `webapp-php/pages/anomalies_public.php` (page "Anomalii și abateri").

**Need**
A page shows a statistical signal about a public document. The reader must see the exact words that caused the signal. The page quotes the paragraph of the source, marks the matched words, and names the source under the quote ("din textul anunțului", "din calendarul concursului").
The DS has `mark` for a matched word and no block for a quotation with its source. A bare `<blockquote>` has the browser style.

**Other projects that can use it**
Every project that cites a source text next to a derived value: search results, extracted facts, review tools, legal references.

**Proposed markup**

```html
<blockquote class="g2-quote">
  <p>Candidații trebuie să aibă <mark>vârsta maximă 45 de ani</mark> la data numirii.</p>
  <footer>din textul anunțului</footer>
</blockquote>
```

**Proposed tokens or CSS** (optional)

```css
.g2-quote { margin: 0; padding: var(--g2-space-2) var(--g2-space-3); border-inline-start: 3px solid rgb(var(--g2-color-line)); background: rgb(var(--g2-color-surface-sunken)); }
.g2-quote footer { font-size: var(--g2-font-size-meta); color: rgb(var(--g2-color-text-muted)); }
```

`mark` keeps `--g2-color-highlight-bg`. The text wraps long words (`overflow-wrap: anywhere`), so the block never causes horizontal scroll at 320 px.

**Local version**
`.pg-quote` in `webapp-php/assets/app.css` (layer `app`).
