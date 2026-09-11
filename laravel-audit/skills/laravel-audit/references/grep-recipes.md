# Grep recipes

Starting points, not verdicts. Every one of these produces false positives — the
command finds candidates, you decide. Examples use `rg` (ripgrep); `grep -rn` works
with adjusted syntax.

Run them from the project root. Add `--glob '!vendor'` if your ripgrep isn't already
honouring `.gitignore`.

## Security

**Raw query fragments**
```bash
rg 'whereRaw|selectRaw|orderByRaw|havingRaw|DB::raw|DB::statement|DB::select|DB::unprepared'
```
Most hits are fine. You're looking for `.` concatenation or `{$var}` interpolation of
anything request-derived. Remember bindings don't cover column names or sort
directions — those need an allowlist.

**Authorisation coverage**
```bash
php artisan route:list --json > /tmp/routes.json     # the full surface
rg 'authorize\(|->can\(|Gate::|can:|@can' app/ routes/ -l
```
The useful move is the difference between the two: routes with no authorisation
reference anywhere in their path. Check controller base classes and middleware groups
before calling it a finding.

**Mass assignment**
```bash
rg 'guarded\s*=\s*\[\]|Model::unguard|->fill\(\$request|create\(\$request->all'
```

**Unvalidated request access**
```bash
rg '\$request->all\(\)|\$request->input\(|\$request->merge\(' app/Http/Controllers/
rg -l 'function rules' app/Http/Requests/ | xargs rg -c 'required|nullable|sometimes'
```
The second tells you which Form Requests have suspiciously few rules for the number of
fields they accept.

**Credentials**
```bash
rg -i 'api[_-]?key|secret|passwd|password|token|bearer|BEGIN (RSA|PRIVATE)' \
   app/ config/ database/ resources/ routes/
```
Then check history for anything real — `git log -S'<the-value>'`. Rotate rather than
delete; the value is already in the history and possibly in a fork.

**`env()` outside config**
```bash
rg 'env\(' app/ routes/ resources/
```
Returns null once config is cached. Silent in production, which is the worst kind.

## Structure

**Fake facades**
```bash
rg -l 'public static function' app/ | xargs rg -l 'private static|protected static|public static \$'
```
Static methods *and* static properties together — that's the state-leak shape. Static
methods alone are usually a legitimate utility class.

**Helpers doing real work**
```bash
rg -l 'function ' --glob '*helpers*.php'
rg 'DB::|::create\(|dispatch\(|Mail::' --glob '*helpers*.php'
```

**Controllers calling controllers**
```bash
rg 'new \w*Controller|Controller\(\)->|Controller::\w+\('
```

**Logic in views**
```bash
rg '@php|<\?php|<\?=' resources/views/
rg '::where|::all\(|::find|DB::|->get\(\)' resources/views/
```

**Fat controllers** — a size proxy, worth a look rather than a finding by itself
```bash
find app/Http/Controllers -name '*.php' | xargs wc -l | sort -rn | head -20
```

## Performance

**Loops over relations in views** — N+1 candidates
```bash
rg -A3 '@foreach' resources/views/ | rg '\->\w+->'
```

**Eager loading present at all**
```bash
rg -c "with\(|withCount\(|load\(" app/ | sort -t: -k2 -rn | head
```
A large app with almost no `with()` calls is a strong N+1 signal in aggregate, even
before you find a specific one.

**Unbounded fetches**
```bash
rg '::all\(\)|->get\(\)' app/ | rg -v 'limit|take|paginate|chunk|cursor|lazy'
```

## Dead code candidates

```bash
rg 'public function (\w+)' app/ -or '$1' | sort | uniq -c | sort -n | head -40
```
Method names defined once and referenced nowhere else. Crude — it misses dynamic
dispatch, framework conventions, and anything called from Blade — so treat it purely
as a shortlist to investigate, never as proof.

Static analysis does this properly. If the project has PHPStan or Psalm configured,
its dead-code and unused-code rules beat any grep here; run those first and use this
only to fill gaps.

## A note on running these

Findings from a grep are candidates. Before writing any of them up:

- open the file and read the surrounding code
- check whether a base class, trait or middleware already handles it
- check whether a test covers the behaviour you think is broken

A list of grep hits pasted into a report is not an audit, and it is obvious to whoever
reads it.
