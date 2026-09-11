---
name: web-security
description: Secure a web application at its edge — Content Security Policy, security response headers, subresource integrity, cookie and session flags, CORS, the reverse-proxy trust boundary, and bot/spam protection. Use when setting up or reviewing headers and CSP, adding a third-party script or font, configuring cookies or CORS, putting an app behind a CDN or proxy, protecting a public form, or when a browser console reports a blocked resource.
---

# Web security at the edge

This skill covers the boundary between a browser and an application: **what the browser
is told it may do, what the application trusts, and what identifies a user.** It is
authoring guidance — decisions you make while building.

**It is not application-level security.** Missing authorisation, unvalidated input,
hard-coded credentials, raw SQL and mass assignment are the `laravel-audit` skill's
territory, and that skill is for auditing code you already have. A perfect CSP on an
endpoint with no authorisation check is a locked window in an open door; the two skills
are complements, not alternatives.

## The model

Three questions, and every rule below answers one of them:

1. **What may the browser do on our behalf?** Which scripts run, which origins can be
   contacted, which features are available. → CSP, Permissions-Policy, SRI.
2. **What does the application trust?** Which proxy set that header, which host the
   request claims to be for, which origins may call us. → trusted proxies, trusted
   hosts, CORS.
3. **What identifies a user, and can it leak?** → cookie flags, session config,
   referrer policy, transport.

A control is only as good as the answer underneath it. HSTS gated on
`$request->isSecure()` is a lie if the proxy in front is not trusted — question 2
silently breaks question 1.

## When to apply

- **Adding anything third-party** — a script, font, analytics tag, embed, captcha,
  payment widget. Each one is a CSP decision, and the moment to make it is now, not
  when the console lights up in production.
- **Adding or changing a public endpoint** — a form, an upload, a webhook receiver.
- **Changing where the app is served from** — a CDN, a new domain, a proxy, a
  subdomain split.
- **Reviewing** — headers drift. So does the list of origins nobody remembers adding.
- **Debugging a blocked resource** — the fix is a directive decision, not a `*`.

**It is a standard, not a task.** Using it once in a session does not discharge it.

### When you catch yourself thinking…

| Thought | Reality |
|---|---|
| "I'll add the CSP at the end" | A CSP added last is a CSP written by whatever already broke. Start restrictive. |
| "`unsafe-inline` just for now" | It defeats the entire script directive. If it ships, it stays. Use a nonce. |
| "It's an internal tool" | Internal tools run in the same browser, with the same session cookies. |
| "The framework default is fine" | A default is a starting point, not a decision. See *CORS* below. |
| "It's only a static site" | A static site with a compromised dependency still exfiltrates whatever it can reach. |
| "I already set headers this session" | It's a standard, not a task. |

## First: read what is already configured

Before adding anything, find out what exists — headers are invisible at the call site
and easy to duplicate or contradict:

1. **Existing response headers.** `curl -sI https://example.com` against the running app.
   Check for a CSP, HSTS, `Referrer-Policy`, `X-Content-Type-Options`. Something in
   front — a CDN, an nginx config, a hosting platform — may already be adding them, and
   two sources setting the same header is a debugging trap.
2. **Where they are set.** Middleware, a package, the web server, the CDN. Add yours
   where the others live.
3. **What is already allowed.** An existing CSP is a list of decisions someone made;
   read it before extending it.
4. **The proxy situation.** Is the app behind Cloudflare, a load balancer, a container
   ingress? That determines whether `isSecure()`, the client IP, and the host are real.
5. **A project convention doc** (`CLAUDE.md`, `.claude/skills/`). It outranks this skill.

## Content Security Policy

A CSP is a list of what the page is allowed to load and contact. It is the one control
here that turns a compromised dependency from "reads every keystroke and posts it
anywhere" into "blocked".

**Start from deny, open what breaks.** The default posture is every fetch directive
`'none'`, then add the ones the app genuinely needs:

```
default-src 'none';
base-uri 'none';
form-action 'none';
frame-ancestors 'none';
img-src 'self';
style-src 'self';
font-src 'self';
connect-src 'self';
script-src 'nonce-{random}';
```

Four of those are the ones people forget, and each closes a specific attack:

- **`base-uri 'none'`** — stops an injected `<base>` tag repointing every relative URL
  on the page.
- **`form-action`** — stops an injected form posting credentials to another origin.
  `default-src` does *not* cover it.
- **`frame-ancestors 'none'`** — clickjacking. This is the modern replacement for
  `X-Frame-Options`; set it here, not in a legacy header.
- **`object-src 'none'`** — legacy plugin content, still a bypass vector.

**Never `unsafe-inline` on scripts.** It disables the directive it appears in. Use a
nonce: a per-response random value in both the header and the tag. If a build tool
produces the tags, it must be the thing generating the nonce, or the two will not
match — see `references/csp.md` for wiring it through a bundler.

**Scope every relaxation.** Dev servers need looser rules than production; that is fine,
provided the loosening is conditional, named, and cannot reach production:

```php
// Only while the dev server is actually running — not "when not production".
if ($this->viteIsRunningHot()) {
    $policy->add(Directive::STYLE, Keyword::UNSAFE_INLINE);
}
```

A relaxation keyed on a *runtime fact* (the hot file exists) is safer than one keyed on
an environment name, because environment names get set wrong.

**Roll it out report-only.** Ship `Content-Security-Policy-Report-Only` with a report
endpoint first, watch what it would have blocked, then enforce. Going straight to
enforcement on an app with any history breaks something you did not predict.

Directive-by-directive reference, nonce mechanics, rollout procedure and the common
breakages: `references/csp.md`.

## The other response headers

Fewer decisions, still worth getting right:

| Header | Value | Note |
|---|---|---|
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains; preload` | **Gate it.** Production and an actually-secure request only. |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Stops full URLs (with their path and query) leaking to third parties. |
| `Permissions-Policy` | `geolocation=(), camera=(), microphone=()` | Deny what the app does not use; add more as the list grows. |
| `X-Content-Type-Options` | `nosniff` | Cheap, no downside. |
| `X-Frame-Options` | — | Superseded by `frame-ancestors`. Set it only for genuinely ancient clients. |

**HSTS is the one that can hurt.** It is a promise the browser remembers for a year:
this host is HTTPS-only. Send it from a local environment on a shared domain and you
have broken plain HTTP for that domain in your own browser, persistently. Gate it:

```php
if (app()->isProduction() && $request->isSecure()) {
    $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload', true);
}
```

Only add `preload` when you mean it — submission to the browser preload list is slow to
undo. And note that `isSecure()` depends on the proxy trust below being correct.

## Subresource integrity

If the app loads a script or stylesheet from a CDN, pin its hash:

```html
<script src="https://cdn.example.com/lib.js"
        integrity="sha384-…"
        crossorigin="anonymous"></script>
```

The browser refuses to execute the file if it does not hash to that value, which is the
only control that survives the CDN itself being compromised. Generate the hashes in the
build — a manual hash goes stale on the next version bump and silently blocks the asset,
or worse, gets removed to make the page work again.

SRI and CSP solve different halves: CSP says *where* a script may come from, SRI says
*which bytes* are acceptable.

## Cookies and sessions

Four settings, all of which should be deliberate:

- **`secure`** — on. Cookie never travels over plain HTTP.
- **`http_only`** — on. Script cannot read it, so an XSS cannot trivially steal the
  session.
- **`same_site`** — `lax` is the right default; `strict` breaks inbound links that
  expect a session; `none` requires `secure` and needs an actual cross-site reason.
- **`domain`** — the narrowest that works. A cookie set on `.example.com` is sent to
  every subdomain, including ones you do not control.

Encrypting session payloads is a separate decision from any of these, and it is not a
substitute for the flags above.

## CORS: a default is not a decision

Framework defaults ship permissive so that nothing blocks during a tutorial. The Laravel
stock config is `allowed_origins => ['*']`, `allowed_methods => ['*']`,
`allowed_headers => ['*']` on `api/*`. That is fine for a public read-only API and wrong
for anything session-authenticated.

The rule: **name the origins.** If you cannot name them, you do not want CORS on that
path. `*` is incompatible with credentialed requests anyway — browsers refuse the
combination — so a `*` sitting next to `supports_credentials => true` is a config that
has never been exercised.

## The trust boundary: proxies and hosts

Behind a CDN or load balancer, `X-Forwarded-For`, `X-Forwarded-Proto` and
`X-Forwarded-Host` are *client-supplied strings* unless the framework is told which
proxies may set them. Get this wrong in either direction and something breaks quietly:

- **Trusting nothing** → every request looks like it came from the proxy's IP and looks
  insecure. Rate limiting keys on one IP; HSTS never sends; redirect-to-HTTPS loops.
- **Trusting everything** → anyone can spoof their IP past a rate limiter or ban list,
  and can claim any host.

Trust the specific proxy IPs or ranges your infrastructure actually uses. Separately,
**set trusted hosts**: an app that echoes an attacker-supplied `Host` into a generated
URL will happily put it in a password-reset email.

More on all of this, plus bot protection: `references/request-trust.md`.

## Bots and public forms

Any unauthenticated endpoint that writes something — contact forms, signups, comments —
gets hit. Layer the cheap controls before the expensive ones:

1. **Honeypot field** — free, invisible to users, catches naive bots.
2. **Timing check** — a form submitted 300ms after render was not filled in by a person.
3. **Rate limit** — per IP *and* per target where one exists.
4. **A challenge** (Turnstile, hCaptcha) — only when the above is not holding. It costs
   real users something, so it is the last layer, not the first.

**Watch for interaction with framework features that pre-submit the form.** Live
validation that posts the form as the user types will trip a honeypot or timing check on
every keystroke unless those requests are excluded:

```php
if ($request->isAttemptingPrecognition()) {
    return $next($request);
}
```

Any bot control also needs a way to see what it rejected. A silent spam filter that
starts eating real submissions looks exactly like "the form went quiet".

## Request identity

Give every request an ID, attach it to the error reporter's scope, and return it as a
response header:

```php
$requestId = (string) Str::uuid();
$request->attributes->add(['request_id' => $requestId]);
$response->headers->set('X-Request-ID', $requestId);
```

It costs nothing and turns "a user says the page broke" into one search. If a proxy in
front already assigns one, adopt that value instead of generating a second.

## Where to stop

- **Don't add a header the app in front already sets.** Duplicate CSP headers are
  intersected by the browser, which produces failures nobody can reproduce.
- **Don't write a CSP you cannot roll back.** Report-only first, always, on a live app.
- **Don't reach for a captcha first.** It is the most expensive layer for real users.
- **Don't copy a header block from a blog post without reading each line.** Half of
  them still recommend `X-XSS-Protection`, which modern browsers ignore and which was
  itself exploitable.
- **This skill stops at the edge.** Authorisation, input validation, secrets and query
  safety are `laravel-audit`.
- **The project wins.** If an app has an established header or CSP setup, extend it
  rather than replacing it.

## Version notes

Verified 2026-09-12. Specifics move; the posture does not. Check against what is
installed:

- **`spatie/laravel-csp` v3 replaced policy classes with presets.** A v2-era
  `extends Spatie\Csp\Policies\Policy` with `addDirective()` is the old API; v3 uses
  `Spatie\Csp\Preset` with `configure(Policy $policy)` and `$policy->add(...)`,
  registered in `config/csp.php` under `presets`.
- v3 config covers several things that used to be hand-rolled: `report_only_presets`
  and `report_uri` for the rollout above, `enabled_while_hot_reloading` for the dev
  server case, and `nonce_generator` for wiring a bundler's nonce in. The
  `Spatie\Csp\Nonce\NonceGenerator` interface (a single `generate(): string`) is
  unchanged between v2 and v3.
- Laravel moved proxy and host trust into `bootstrap/app.php`
  (`->withMiddleware(fn (Middleware $m) => $m->trustProxies(at: [...]))`) rather than
  published middleware classes.
