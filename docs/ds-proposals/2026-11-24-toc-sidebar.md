**Type:** component

**Breaking:** no

**From project:** gov2-ro/posturi.gov.ro, `webapp-php/pages/anomalies_public.php` (page "Anomalii și abateri") and `webapp-php/partials/toc.php` (Statistici, Despre).

**Need**
A long page needs a table of contents (TOC). The page has groups, and each group has sections, so the TOC has two levels. The reader must reach a section at once and must see where they are.
From 1024 px the TOC is a sticky column beside the content. Below 1024 px it is a "Cuprins" block (`<details>`) at the top of the page. The DS says not to make the whole header sticky. This TOC makes only the TOC sticky. The DS has no component for in-page navigation.
Two pages of this project already use a one-level version of it.

**Other projects that can use it**
Every project with a long page: methodology pages, reports, statistics pages, legal text.

**Proposed markup**

```html
<!-- below 1024px -->
<details class="g2-toc-block">
  <summary>Cuprins</summary>
  <nav aria-label="Cuprins" data-toc>
    <ul>
      <li><a href="#grup-a">Group A</a>
        <ul><li><a href="#sectiune-1"><span>Section 1</span> <span class="g2-toc-n">12</span></a></li></ul>
      </li>
    </ul>
  </nav>
</details>
<!-- from 1024px: the same list in a sticky column -->
<nav class="g2-toc" aria-label="Cuprins" data-toc>…</nav>
```

The section in view gets `aria-current="true"`. A small script sets it (IntersectionObserver). Without script both lists work and have no mark.

**Proposed tokens or CSS** (optional)

```css
.g2-toc { position: sticky; top: var(--g2-space-4); max-height: calc(100vh - 2rem); overflow-y: auto; }
.g2-toc a[aria-current="true"] { font-weight: 700; border-inline-start: 3px solid rgb(var(--g2-color-link)); }
```

The mark is bold with a bar, so it does not rest on colour alone. The links are at least 44 px high on touch screens. The page has no horizontal scroll at 320 px.

**Local version**
`.pg-atoc-small`, `.pg-atoc-side` and `.pg-atoc-*` in `webapp-php/assets/app.css` (layer `app`). The older one-level version is `.pg-toc`.
