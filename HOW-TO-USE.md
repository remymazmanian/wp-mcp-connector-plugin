# WP MCP Connector: How to Use It

Practical guide for publishing from an AI client. Focuses on the failure modes that actually happen and how they look from the model’s side.

Full tool and setting reference lives in `README.md`. This file is about getting real work through the pipe.

---

## 1. The one call that does everything

`wp_publish_article` creates a finished post in a single call: body, featured image, in-article images at named paragraphs, categories, tags, and SEO.

```json
{
  "title": "What a Full Roof Replacement Actually Costs",
  "status": "draft",
  "content": "<!-- wp:paragraph --><p>...</p><!-- /wp:paragraph -->",
  "excerpt": "One sentence for the card and the meta description.",
  "categories": ["Guides"],
  "seo": {
    "seo_title": "Under 60 characters",
    "meta_description": "120 to 155 characters."
  },
  "featured_image": {
    "url": "https://example.com/photo.jpg",
    "alt": "What is actually in the frame, not the post title",
    "credit": "Photographer via Unsplash",
    "license": "Unsplash License"
  },
  "images": [
    {
      "url": "https://example.com/second.jpg",
      "alt": "Describe this frame too",
      "after_paragraph": 3,
      "caption": "Optional",
      "license": "Unsplash License"
    }
  ]
}
```

Prefer this over separate create + upload + insert + SEO calls. If an image fails, the article is still created and the response names the failure, so nothing is lost.

**Filenames are derived from `alt` when you omit one.** That is usually correct — a descriptive filename is the one SEO field that cannot be fixed later without breaking URLs.

---

## 2. Images (the part that decides whether this works)

### Ranking — most reliable first

| # | Method          | When                              | Calls   |
|---|-----------------|-----------------------------------|---------|
| 1 | `url`           | Image exists at any fetchable URL | **1**   |
| 2 | `attachment_id` | Already in the media library      | 1       |
| 3 | `upload_id`     | Finishing a chunked transfer      | n + 2   |
| 4 | `base64`        | Small file, inline                | 1       |

**`url` wins whenever it is available.** The server downloads the file itself, so large payloads never pass through the model’s output.

### Why the other methods disappoint

The server is not the bottleneck. Measured limits:

- `max_upload_bytes`: 32 MB
- PHP `upload_max_filesize`: 10 MB
- `chunk_characters`: 500–400,000

The real limit is the **client’s tool-calling layer truncating outbound arguments**. Observed: one client sent ~773–2,600 characters per call while the server accepted 50,000. A 112 KB image can require ~180 round-trips under heavy truncation. Another client moved 123 KB in 10 calls over the identical protocol, so this varies enormously by client and cannot be fixed on the server.

If you read anywhere that base64 is capped near 50 KB, that was advisory prose in an older build of this plugin's tool descriptions, never a real check. It has been removed. No such limit exists in the code.

### Staging pattern (best route for generated images)

When the model generates an image and only holds the bytes in its sandbox:

1. Connect a file-storage connector to the same AI client (Google Drive, Dropbox, S3, anything that yields a direct-download URL).
2. Have the model write the generated image there.
3. Pass the direct-download URL to `wp_publish_article` or `wp_upload_media`.

Measured result: five generated cards (330–383 KB each) landed in twenty seconds after pure chunking had failed for hours.

**Google Drive URL shape matters:**

```
GOOD  https://drive.google.com/uc?export=download&id=FILE_ID
BAD   https://drive.google.com/file/d/FILE_ID/view
```

The `/view` form returns HTML. The file must be shared so anyone with the link can read it, or the fetch returns 403.

### What the fetcher does

- Follows up to 5 redirects, 45 s timeout
- Sends a browser-like User-Agent
- Rejects HTML responses
- Derives extension from `Content-Type`, then by sniffing bytes
- Distinguishes 401/403 from other errors

### Record provenance at upload time

Every image accepts `credit` and `license` (stored as `_wpmcp_credit`, `_wpmcp_license`, `_wpmcp_source_url`).

Set them on the way in. Reconstructing later which of forty-five attachments were generated versus photographed is painful, and a staging URL is not provenance: it records where the bytes passed through, not what the image is, and those links rot when the file moves. For generated images, `"license": "AI generated"` is cheap and permanent.

---

## 3. Chunked upload (only when you truly have no URL)

```
wp_begin_media_upload  → upload_id
wp_append_media_chunk  → repeat using next_offset
wp_finish_media_upload → attachment_id
```

Offset-based and truncation-tolerant. A cut-off call is not a failure — the server keeps every character received and returns `next_offset`. Never restart.

Lower `chunk_characters` and send more calls if the client truncates badly. Set `post_id` and `set_featured` at the begin step so the image attaches automatically.

---

## 4. Troubleshooting

### “The tool does not exist” / “I cannot see the new tool”

**Cause:** Tool definitions are read once, at connection time.  
**Fix:** Reconnect the client. After shipping a new tool, tell the operator to reconnect rather than assuming discovery.

### “That option is restricted / I do not have permission”

**Cause:** Almost always a guessed option name, not a real permission wall.  
- A true refusal names the allowlist.  
- A null/false value means the option does not exist.

The allowlist accepts `*`, which permits everything except `siteurl` and `home` (those remain guarded even under the wildcard).

### Connector keeps going down and needs re-authorization

**Cause:** Credentials stored in transients on a site with a persistent object cache. Cache flushes (common in deploys) destroy them.  
**Fix:** This plugin stores OAuth tokens in a real option (`wpmcp_oauth_tokens`). Prefer deleting page-cache files over running a full object-cache purge during deploys.

### Chunked upload finishes but image never appears

Check that the target post still exists. Sessions store `post_id` at the begin step. If the post was trashed and recreated, the transfer completes into nothing. Restart against the new ID. Inspect open sessions in the `wpmcp_uploads_in_progress` option.

### Published changes not visible on the site

Work outward, cheapest first:

1. **PHP opcache.** Usually self-correcting where `validate_timestamps` is on; check `revalidate_freq`.
2. **Disk page cache** (`advanced-cache.php` / `WP_CACHE`). Can serve stale HTML even when the plugin looks inactive. Deleting the generated HTML is safe and does not touch transients, so live MCP sessions survive.
3. **Varnish.** Purge with a `PURGE` request to the local instance and the right Host header. Read the admin port off the running process first: `varnishadm` with no arguments will not find the daemon and reports that it is not running.
4. **Edge/CDN cache.** Usually only purgeable from the host's control panel.

Quick diagnostic: request the same page with a query string (`/page/?v=123`). If that is fresh and the clean URL is stale, the cache is keyed on the exact URL.

### Images download but are not really images

A suspiciously small “JPEG” is often an HTML interstitial (share page, login wall, virus scan). Check real dimensions. The fetcher rejects obvious HTML, but some edge cases can slip through.

### SEO fields write but nothing shows

Check which backend was detected (Yoast, Rank Math, SEOPress are automatic). For a custom SEO implementation:

```php
add_filter( 'wpmcp_seo_backend', fn( $b ) => 'none' === $b ? 'custom' : $b );
add_filter( 'wpmcp_seo_meta_prefix', fn() => '_yoursite_seo_' );
```

Use `wpmcp_seo_key_map` when keys do not share one prefix.

---

## 5. Give the model its house rules

The connector is transport only. It does not know your editorial standards — and should not, so the plugin stays reusable.

Publish your authoring rules as a readable option, then instruct the model:

```
Call wp_get_option with name "yoursite_handoff" before writing anything.
```

Generate that option from live data so the rules cannot drift from what the code enforces.

---

## 6. Before you publish, every time

1. Featured image set, with `alt` that describes the frame (not the title)
2. `credit` and `license` recorded on every image
3. Excerpt written
4. SEO title < 60 characters, meta description 120–155
5. Every figure has a named source, or no attribution at all
6. Status is `draft` unless you intentionally chose `publish`

Vague attribution (“according to industry trackers”) is worse than none. Name the source or say nothing.
