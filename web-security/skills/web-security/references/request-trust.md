# What the application trusts

Verified 2026-09-12. The other half of edge security: not what the browser is told, but
what arrives and how much of it can be believed.

## Reverse proxies

Behind a CDN, load balancer or ingress, the app sees the *proxy's* connection. Anything
about the original client arrives in headers — `X-Forwarded-For`, `X-Forwarded-Proto`,
`X-Forwarded-Host`, `X-Forwarded-Port` — which are, until configured otherwise,
attacker-supplied strings.

Both failure directions are quiet:

**Trusting nothing.** Every request appears to come from the proxy's IP over plain HTTP.
Consequences: rate limiters key on a single IP and throttle everyone at once; IP-based
blocks are useless; `isSecure()` is false so an HSTS gate never fires and a
redirect-to-HTTPS rule can loop; generated URLs come out `http://`.

**Trusting everything.** Any client can claim any IP, defeating rate limits, ban lists
and geo rules, and can claim any host.

**Trust the specific proxies.** Name the IPs or ranges the infrastructure actually uses,
and refresh them when the provider's list changes. Where a CDN publishes its ranges, a
package that tracks them beats a hard-coded list that rots. `'*'` is only defensible
when the app is genuinely unreachable except through the proxy — and that is a network
claim, so verify it rather than assuming it.

In current Laravel this lives in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustProxies(at: ['10.0.0.0/8']);
})
```

Match the headers to what the proxy actually sends; AWS ELB uses its own header, and
trusting headers nothing sets is harmless but misleading to the next reader.

## Trusted hosts

Separately: the `Host` header is client-supplied. An app that reflects it into a
generated absolute URL will put an attacker's domain into a password-reset email — the
classic host-header poisoning bug, and it survives every other control on this page
because the email is generated server-side and looks legitimate.

Set an allowlist of hosts the app answers to. Anything else gets a 4xx. If the app
serves several domains (a marketing host and an app host, say), list them all, and treat
a request for an unlisted host as a bug rather than something to redirect helpfully.

Related: a canonical-host redirect (apex → www, or any alias → the real domain) belongs
in the same place, and should be a 301 to a URL built from *config*, never from the
incoming host.

## Cookies

| Setting | Default to | Notes |
|---|---|---|
| `secure` | `true` | Never sent over plain HTTP. Requires HTTPS locally, which is worth setting up anyway. |
| `http_only` | `true` | Script cannot read it. Turns many XSS bugs from "session stolen" into "annoying". |
| `same_site` | `lax` | `strict` logs users out when arriving from an external link; `none` demands `secure` and a real cross-site reason. |
| `domain` | narrowest that works | `.example.com` sends the cookie to every subdomain — including a compromised or third-party-hosted one. |
| `path` | `/` usually | Narrowing rarely helps; it is not a security boundary. |
| session lifetime | as short as tolerable | Paired with "expire on close" for anything sensitive. |

Two things these flags do **not** do: they do not stop CSRF (that is a token, plus
`same_site` helping), and they do not encrypt the payload. Encrypting session data is a
separate decision, mostly relevant when sessions are stored somewhere you do not fully
control.

## CORS

CORS does not protect your API. It tells *browsers* which origins may read responses
from it — a non-browser client ignores it entirely. So:

- **CORS is not authorisation.** An endpoint that needs a token still needs the token
  check. Locking down CORS on an unauthenticated endpoint protects nobody.
- **Name the origins.** `'*'` means every site on the internet may read responses in
  their users' browsers. Fine for a genuinely public read-only API; wrong for anything
  session-authenticated.
- **`'*'` and credentials are mutually exclusive.** Browsers reject the combination, so
  `allowed_origins => ['*']` beside `supports_credentials => true` is a configuration
  that has never worked — a reliable sign nobody exercised it.
- **Scope the paths.** CORS on `api/*` is a decision about that prefix; check nothing
  session-authenticated lives under it.
- **Methods and headers too.** `['*']` for both is the tutorial default, not a choice.

## Rate limiting

Rate limits are an edge control and fail the same way proxies do — keyed on the wrong
thing, they either do nothing or lock out everyone.

- **Key on what you are protecting.** Login: the account *and* the IP, so one attacker
  cannot lock out a user by guessing at their email, and one IP cannot spray many
  accounts.
- **Limit expensive endpoints, not just auth.** Search, exports, anything that fans out
  to a third party.
- **Return `429` with `Retry-After`.** A silent drop is indistinguishable from an outage
  to a legitimate client.
- **Remember the proxy.** Without trusted proxies configured, every limit keyed on IP is
  keyed on one IP.

## Bots on public forms

Layer cheap to expensive, and stop when it holds:

1. **Honeypot** — a field real users never fill, hidden with CSS rather than
   `type="hidden"`. Free; catches naive bots.
2. **Timing** — reject submissions that arrive implausibly fast after render. Needs a
   signed timestamp in the form, or it is trivially forged.
3. **Rate limit** — per IP and per target.
4. **Challenge** (Turnstile, hCaptcha, reCAPTCHA) — real cost to real users,
   accessibility implications, and a third-party script that now needs a CSP entry.
   Last resort, not first.

**Gotchas that make these misfire:**

- **Live validation.** Frameworks that submit the form as the user types (Laravel
  Precognition, and similar) trip honeypot and timing checks continuously. Exclude those
  requests explicitly:

  ```php
  if ($request->isAttemptingPrecognition()) {
      return $next($request);
  }
  ```

- **Non-POST requests** reaching a spam middleware that only makes sense for writes.
- **Password managers and autofill** filling a honeypot that is hidden badly. Hide with
  CSS positioning, set `autocomplete="off"` and `tabindex="-1"`.
- **Silence.** Log or count rejections. A spam filter that starts eating real
  submissions is indistinguishable from "the form went quiet" — which is exactly how it
  gets noticed three weeks late.

## Request identity

One ID per request, attached to the error reporter and returned to the client:

```php
$requestId = (string) Str::uuid();
$request->attributes->add(['request_id' => $requestId]);

if (app()->bound('sentry')) {
    configureScope(fn (Scope $scope) => $scope->setTag('request_id', $requestId));
}

$response->headers->set('X-Request-ID', $requestId);
```

If something upstream already assigns one (most CDNs and ingresses do), adopt that value
rather than generating a second — two IDs for one request is worse than none, because
neither joins up across the hop.
