# BarberSure Discovery & SEO Additions

This document summarizes the newly added public discovery and SEO-oriented features.

## New Public Pages

1. `discover.php`

    - Public directory of approved barbershops.
    - Filters: keyword (shop name / city), city, service, verified only, per-page size.
    - Pagination with accessible navigation links.
    - Displays verification badge and aggregate rating (if reviews exist) or a placeholder when none.

2. `sitemap.php`

    - Generates a dynamic XML sitemap listing: home page, discovery page, and each approved shop.
    - Uses `registered_at` as `lastmod` where available.
    - Adjust the `$base` variable to your production domain.

3. `api/shops_search.php`
    - Returns JSON for the same filters used in `discover.php` (progressive enhancement / future AJAX integration).
    - Response shape: `{ data: [...], total, page, per_page }`.

## Modified Pages

-   `index.php`

    -   Added navigation link to `discover.php`.
    -   Added Open Graph metadata & JSON-LD `WebSite` schema.

-   `discover.php`
    -   Added Open Graph + canonical + JSON-LD `CollectionPage` schema.

## SEO / Structured Data

-   Open Graph tags support richer previews (adjust `og:image` URLs with the actual deployed asset).
-   JSON-LD `WebSite` + `SearchAction` enables potential search enhancements in search engines.
-   JSON-LD `CollectionPage` describes the directory.

## Future Enhancements (Suggested)

-   Add a `shop_details.php` implementation with LocalBusiness schema and optional `AggregateRating`.
-   Introduce slugs for cleaner shop URLs (e.g., `/shop/fade-district-12`).
-   Cache filter option lists if database grows large.
-   Implement rate limiting / ETag headers for the JSON API.
-   Add server-side generated `<link rel="next/prev">` tags for pagination.
-   Add robots.txt referencing `/sitemap.php`.

## Security & Hardening

-   Public endpoints only read approved shops; ensure future changes keep `status='approved'` constraint.
-   If adding user-generated content (reviews/comments), sanitize output via existing `e()` helper.

## Deployment Notes

-   Replace placeholder domain `https://example.com` in:
    -   `index.php` (OG + canonical + JSON-LD)
    -   `discover.php` (OG + canonical + JSON-LD)
    -   `sitemap.php` `$base` variable.
-   Provide a real `og:image` asset (1200x630 recommended) and ensure it is publicly accessible.

## Testing Checklist

-   Visit `/discover.php` without params: results show or empty state message.
-   Apply each filter individually & combined; confirm counts adjust.
-   Test pagination at edges (page=1, large page beyond last -> auto snap to last).
-   Access `/api/shops_search.php?q=test` returns JSON and `Content-Type: application/json`.
-   Validate `/sitemap.php` via an XML linter.

---

Authored automatically via enhancement workflow. Update this file as discovery evolves.
