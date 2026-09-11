# Content Security Policy

Verified 2026-09-12. The directives are a W3C spec and move slowly; defer to
[MDN's CSP reference](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy)
over this file where they disagree.

## The directives that matter

| Directive | Set it to | Why |
|---|---|---|
| `default-src` | `'none'` | The fallback for most fetch directives. Starting at `'none'` makes every allowance explicit. |
| `script-src` | `'nonce-…'`, or `'self'` | The one that actually stops an injected script. |
| `style-src` | `'self'` + nonce | Inline styles are a smaller risk than scripts but still leak data via selectors. |
| `img-src` | `'self'` + CDN | Images are a common exfiltration channel (`new Image().src = '…?data'`). |
| `connect-src` | `'self'` + named APIs | Covers fetch, XHR, WebSocket, `sendBeacon`. This is the exfiltration directive. |
| `font-src` | `'self'` + font host | |
| `frame-src` | `'none'` unless embedding | What this page may embed. |
| `frame-ancestors` | `'none'` or named origins | Who may embed **this** page. Clickjacking. Not covered by `default-src`. |
| `form-action` | `'none'` or `'self'` | Where forms may post. Not covered by `default-src`. |
| `base-uri` | `'none'` | Stops an injected `<base>` rewriting every relative URL. Not covered by `default-src`. |
| `object-src` | `'none'` | Legacy plugin content. |
| `upgrade-insecure-requests` | present, no value | Rewrites `http:` subresource URLs to `https:`. |

The three "not covered by `default-src`" rows are the ones that get missed. A policy
with a perfect `default-src 'none'` and no `form-action` still lets an injected form
post the page's inputs to another origin.

## Nonces

A nonce is a fresh random value per response, in both places:

```
Content-Security-Policy: script-src 'nonce-r4nd0m…'
```

```html
<script nonce="r4nd0m…">…</script>
```

Rules that make nonces work rather than quietly fail:

- **One value per response**, generated per request. A nonce reused across responses,
  or worse baked into a cached page, is no better than `unsafe-inline`.
- **The framework that renders the tags must own the nonce.** If a bundler emits the
  script tags, it has to be the source of the value; a separately-generated nonce in
  the header will never match. Point the CSP layer at the bundler:

  ```php
  class ViteNonceGenerator implements NonceGenerator
  {
      public function generate(): string
      {
          return Vite::useCspNonce();
      }
  }
  ```

  `Vite::useCspNonce()` both generates the value and makes Vite stamp it on every tag
  it renders, which is what keeps the two halves in sync.
- **`'strict-dynamic'`** lets a nonce'd script load further scripts without each one
  being listed. Useful with a bundler that injects chunks at runtime; it also means a
  compromise of the entry script inherits its privileges. Worth it for most SPAs.
- **Hashes instead of nonces** (`'sha256-…'`) work for a genuinely fixed inline block,
  and survive full-page caching where nonces cannot. They break the moment the block
  changes by one byte, so generate them in the build.

**A nonce is incompatible with caching a whole HTML response.** If pages are cached at
a CDN, either exclude the HTML, or use hashes, or accept `'self'` with no inline scripts
at all.

## Rolling one out

On an app with any history, going straight to enforcement breaks something nobody
predicted. The sequence:

1. **Report-only, with a report endpoint.** Ship
   `Content-Security-Policy-Report-Only` alongside no enforcing policy. Nothing breaks;
   violations are collected.
2. **Watch for a full traffic cycle.** A week covers the weekly admin export nobody
   thinks about. Marketing pages, checkout, admin panels and email-rendered pages all
   have different resource profiles.
3. **Triage the reports.** Expect three categories:
   - real allowances you forgot (a font host, an embed),
   - things that should be removed rather than allowed (an ancient analytics snippet),
   - **noise from browser extensions**, which report against your policy but are not
     your page. Filter by the injected scheme (`chrome-extension:`, `moz-extension:`)
     before reading counts.
4. **Enforce**, keeping report-only alongside for the *next* set of changes. Running
   both headers is normal: one enforces today's policy, one tests tomorrow's.

## Common breakages, and what they mean

| Symptom | Cause | Fix |
|---|---|---|
| Inline `onclick` stops working | Inline event handlers are script | Move to an addEventListener; a nonce does not cover attributes |
| A library's injected `<style>` is dropped | Runtime style injection | Nonce the style directive, or use the library's build-time CSS |
| Images from `data:` URIs blocked | `img-src` has no `data:` | Add `data:` to `img-src` only — never to `script-src` |
| A worker fails to start | `worker-src` falls back to `script-src`, `blob:` missing | Add `worker-src 'self' blob:` |
| Source maps fail in dev | Dev server origin not in `connect-src` | Scope the dev allowance to the hot-reload case |
| Everything breaks in production only | Two policies from two places | `curl -sI` and count the CSP headers — browsers enforce the **intersection** of all of them |

That last row is the one that eats an afternoon. A CDN, a hosting platform and the app
can each add a CSP; the result is stricter than any of them alone and matches no single
config file.

## Reporting

`report-uri` is deprecated in favour of the `Reporting-Endpoints` header plus
`report-to`, but support is uneven — sending both is still the pragmatic choice.

Point them somewhere that aggregates. Raw CSP reports at any traffic volume are
unreadable, and the extension noise above will dominate the raw feed.

## Laravel specifics

`spatie/laravel-csp` v3 is config-driven around **presets** (classes implementing
`Spatie\Csp\Preset` with `configure(Policy $policy)`), registered in `config/csp.php`.
The v2-era `extends Policies\Policy` with `addDirective()` is the previous API — code
written against it needs rewriting, though `Spatie\Csp\Nonce\NonceGenerator` itself is
unchanged.

Three config keys do work that is otherwise hand-rolled:

- `report_only_presets` / `report_uri` — the rollout sequence above.
- `enabled_while_hot_reloading` — the dev-server case, instead of a custom check.
- `nonce_generator` — where the Vite generator above is registered.

The middleware can be global or per-route, and a preset can be passed per route:
`->middleware(AddCspHeaders::class . ':' . MyPreset::class)`.
