#!/usr/bin/env python3
"""Discover public released plugins from the trusted owner; registry is exceptions only.
Run explicitly after releases. Never schedules work or expands executable legacy trust.
"""
import argparse
import datetime
import hashlib
import json
import re
import subprocess
import stat
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SLUG = "as-update-controller"
BRAND = "AlphaSys"
OWNER = "cchatterton"
IDENTITY = ("id", "owner", "repo", "file", "slug", "asset", "author")
MAX_ASSET = 67108864


def gh(*args):
    return subprocess.check_output(["gh", *args], timeout=120)


def header(text, key, default=""):
    match = re.search(r"^[ \t]*\*?[ \t]*" + re.escape(key) + r":[ \t]*([^\r\n]*)$", text, re.M)
    return match.group(1).strip() if match else default


def domain_policy(text):
    raw = header(text, "Allowed Domains")
    domains = [d.strip().lower() for d in raw.split(",")] if raw else []
    subdomains = header(text, "Allow Subdomains", "false").lower()
    if subdomains not in ("true", "false") or any(len(d) > 253 or not re.fullmatch(r"localhost|[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+", d) for d in domains):
        raise ValueError("Invalid Allowed Domains / Allow Subdomains plugin headers")
    return {"allowed_domains": domains, "include_subdomains": subdomains == "true"}


def repositories():
    # Pagination is essential: new plugins must not disappear beyond the first page.
    query = '''query($endCursor:String) { user(login:"cchatterton") {
      repositories(first:100,after:$endCursor,privacy:PUBLIC,ownerAffiliations:OWNER) {
        pageInfo { hasNextPage endCursor }
        nodes { name isArchived isFork latestRelease { tagName isDraft isPrerelease } }
      }
    } }'''
    pages = json.loads(gh("api", "graphql", "--paginate", "--slurp", "-f", "query=" + query))
    result = []
    for page in pages:
        if page.get("errors"):
            raise ValueError("Repository enumeration failed")
        result.extend(page["data"]["user"]["repositories"]["nodes"])
    return result


def inspect_package(path, repo, asset, release, exception=None):
    exception = exception or {}
    with zipfile.ZipFile(path) as archive:
        names = archive.namelist()
        if len(names) != len(set(names)) or any(n.startswith("/") or "\\" in n or ".." in n.split("/") for n in names):
            raise ValueError("Unsafe or duplicate archive path: " + repo)
        if any(stat.S_ISLNK(i.external_attr >> 16) for i in archive.infolist()):
            raise ValueError("Symlinks are not permitted in release packages: " + repo)
        if sum(i.file_size for i in archive.infolist()) > 268435456:
            raise ValueError("Oversized expanded package: " + repo)
        candidates = []
        for info in archive.infolist():
            if len(info.filename.split("/")) != 2 or not info.filename.endswith(".php"):
                continue
            if info.file_size > 2097152:
                raise ValueError("Oversized main file: " + repo)
            text = archive.read(info).decode("utf-8", errors="replace")[:8192]
            if header(text, "Plugin Name"):
                candidates.append((info.filename, text))
        if exception.get("file"):
            candidates = [c for c in candidates if c[0] == exception["file"]]
        if not candidates:
            return None  # A theme, source archive, or other non-plugin release.
        if len(candidates) != 1:
            raise ValueError("Ambiguous plugin main file; add an exception: " + repo)
        file, text = candidates[0]
        slug = file.split("/")[0]
        author = header(text, "Author")
        expected_author = exception.get("author_header", BRAND)
        if author.casefold() != expected_author.casefold():
            if exception:
                raise ValueError("Release author differs from approved exception: " + repo)
            return None
        if any(not n.startswith(slug + "/") for n in names):
            raise ValueError("Unexpected package root: " + repo)
        if not exception and (not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9_-]*", slug) or not re.fullmatch(r"[a-zA-Z0-9_-]+\.php", file.split("/")[1]) or asset != slug + ".zip"):
            raise ValueError("Nonstandard package identity requires an exception: " + repo)
        uri = header(text, "Update URI")
        if uri and uri.rstrip("/") != "https://github.com/" + OWNER + "/" + repo:
            raise ValueError("Release Update URI differs from repository: " + repo)
        version = header(text, "Version")
        if release["tag_name"] not in (version, "v" + version, "V" + version):
            raise ValueError("Plugin header/tag mismatch: " + repo)
        entry = dict(id=repo, owner=OWNER, repo=repo, file=file, slug=slug, asset=asset, author=BRAND,
                     name=header(text, "Plugin Name"), description=header(text, "Description"))
        for key in IDENTITY:
            if key in exception and exception[key] != entry[key]:
                # Stable catalogue aliases can differ from the repository name.
                if key == "id":
                    entry[key] = exception[key]
                else:
                    raise ValueError("Exception identity mismatch: " + repo + ": " + key)
        entry["beta"] = exception.get("beta", repo != SLUG)
        if type(entry["beta"]) is not bool:
            raise ValueError("Invalid beta status: " + repo)
        entry["exclusive"] = exception.get("exclusive", False)
        if type(entry["exclusive"]) is not bool:
            raise ValueError("Invalid exclusive status: " + repo)
        entry.update(domain_policy(text))
        if "allowed_domains" in exception:
            raw = ",".join(exception["allowed_domains"])
            entry.update(domain_policy("Allowed Domains: " + raw + "\nAllow Subdomains: " + str(exception.get("include_subdomains", False)).lower()))
        entry.update(version=version, tag=release["tag_name"], requires=header(text, "Requires at least", "6.0"),
                     requires_php=header(text, "Requires PHP", "7.4"),
                     dependencies=[x.strip() for x in header(text, "Requires Plugins").split(",") if x.strip()],
                     controller_api=int(header(text, BRAND + " Controller API", "1" if repo == SLUG else "0")),
                     body=release.get("body") or "See the published release notes.", sha256=hashlib.sha256(path.read_bytes()).hexdigest())
        for key in ("version", "requires", "requires_php"):
            if not re.fullmatch(r"\d+\.\d+(?:\.\d+){0,2}", entry[key]):
                raise ValueError("Invalid " + key + ": " + repo)
        if any(not re.fullmatch(r"[a-z0-9-]+", d) for d in entry["dependencies"]):
            raise ValueError("Invalid dependency: " + repo)
        return entry


def collect(repo, exception=None):
    exception = exception or {}
    release = json.loads(gh("api", "repos/" + OWNER + "/" + repo + "/releases/latest"))
    if release.get("draft") or release.get("prerelease"):
        raise ValueError("Only published stable releases are supported")
    assets = [a for a in release["assets"] if a["name"].endswith(".zip") and (not exception.get("asset") or a["name"] == exception["asset"])]
    if len(assets) > 20:
        raise ValueError("Too many ZIP assets; add an exception: " + repo)
    records = []
    for asset in assets:
        if asset["size"] > MAX_ASSET or not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9_.-]*\.zip", asset["name"]):
            raise ValueError("Unsafe or oversized asset: " + repo)
        with tempfile.TemporaryDirectory() as temp:
            subprocess.run(["gh", "release", "download", release["tag_name"], "--repo", OWNER + "/" + repo, "--pattern", asset["name"], "--dir", temp], check=True, timeout=120)
            path = Path(temp) / asset["name"]
            if path.stat().st_size != asset["size"]:
                raise ValueError("Asset size changed: " + repo)
            record = inspect_package(path, repo, asset["name"], release, exception)
            if record:
                records.append(record)
    if len(records) > 1:
        raise ValueError("Multiple plugin assets; select one in exceptions: " + repo)
    if exception and not records:
        raise ValueError("Exception has no verified release asset: " + repo)
    return records[0] if records else None


def discover(repos, exceptions, previous, collector=collect, report=True):
    by_repo = {}
    for key, entry in exceptions.items():
        if not isinstance(entry, dict):
            raise ValueError("Exception must be an object: " + key)
        for flag in ("include", "exclude", "beta", "exclusive", "include_subdomains"):
            if flag in entry and type(entry[flag]) is not bool:
                raise ValueError("Exception flag must be boolean: " + key + ": " + flag)
        if "allowed_domains" in entry and (not isinstance(entry["allowed_domains"], list) or any(not isinstance(d, str) for d in entry["allowed_domains"])):
            raise ValueError("Exception domains must be a list of hostnames: " + key)
        if entry.get("owner", OWNER) != OWNER or entry.get("author", BRAND) != BRAND:
            raise ValueError("Exception is outside this controller: " + key)
        repo = entry.get("repo", key)
        if repo in by_repo:
            raise ValueError("Duplicate exception repository: " + repo)
        by_repo[repo] = entry
    records = []
    processed = set()
    for repo in sorted(repos, key=lambda r: r["name"]):
        name = repo["name"]
        exception = by_repo.get(name, {})
        if exception.get("exclude") is True or exception.get("superseded_by"):
            processed.add(name)
            continue
        release = repo.get("latestRelease")
        if not release or release.get("isDraft") or release.get("isPrerelease"):
            continue
        if (repo.get("isFork") or repo.get("isArchived")) and not exception.get("include"):
            continue
        record = collector(name, exception)
        processed.add(name)
        if record:
            records.append(record)
            if report:
                print(record["id"] + " " + record["version"] + " verified", flush=True)
    for name, entry in by_repo.items():
        if name not in processed and not entry.get("exclude") and not entry.get("superseded_by"):
            raise ValueError("Exception is missing a public stable release: " + name)
    if not records or len(records) > 200:
        raise ValueError("Empty or oversized discovery snapshot")
    seen = {key: set() for key in ("id", "repo", "file", "slug")}
    old = {e["id"]: e for e in previous.get("plugins", [])}
    for entry in records:
        for key, values in seen.items():
            if entry[key].casefold() in values:
                raise ValueError("Duplicate " + key + ": " + entry[key])
            values.add(entry[key].casefold())
        if entry["id"] in old and any(entry[k] != old[entry["id"]][k] for k in IDENTITY):
            raise ValueError("Published identity changed: " + entry["id"])
        if entry["id"] in old and entry.get("tag") == old[entry["id"]].get("tag") and entry.get("sha256") != old[entry["id"]].get("sha256"):
            raise ValueError("Published bytes changed under the same tag: " + entry["id"])
    # A missing/invalid former release is not silently withdrawn. Exclusion is explicit.
    repos_now = {e["repo"] for e in records}
    for entry in old.values():
        rule = by_repo.get(entry["repo"], {})
        if entry["repo"] not in repos_now and not rule.get("exclude") and not rule.get("superseded_by"):
            raise ValueError("Previously published plugin missing; review or explicitly exclude: " + entry["repo"])
    return sorted(records, key=lambda e: e["id"])


def discover_one(name, exceptions, previous, collector=collect):
    """Release-time refresh of one repository; other verified entries are preserved."""
    if not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9_.-]*", name):
        raise ValueError("Use a repository name owned by " + OWNER)
    info = json.loads(gh("api", "repos/" + OWNER + "/" + name))
    if info.get("name") != name or info.get("owner", {}).get("login") != OWNER or info.get("private") is not False:
        raise ValueError("Repository is outside the public owner scope")
    existing = {e["repo"]: e for e in previous.get("plugins", [])}
    repos = [dict(name=repo, latestRelease={"isDraft": False, "isPrerelease": False}) for repo in existing if repo != name]
    repos.append(dict(name=name, isFork=info.get("fork"), isArchived=info.get("archived"), latestRelease={"isDraft": False, "isPrerelease": False}))
    records = discover(repos, exceptions, previous, lambda repo, rule: collector(repo, rule) if repo == name else existing[repo], report=False)
    selected = next((e for e in records if e["repo"] == name), None)
    print(name + (" " + selected["version"] + " verified" if selected else " explicitly excluded"), flush=True)
    print(str(sum(e["repo"] != name for e in records)) + " other verified entries retained", flush=True)
    return records


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true", help="Verify discovery without writing metadata")
    parser.add_argument("--repo", help="Refresh only this released repository, preserving other verified entries")
    parser.add_argument("--expect-version", help="Fail unless --repo resolves to this released version")
    args = parser.parse_args()
    if args.expect_version and not args.repo:
        parser.error("--expect-version requires --repo")
    exceptions = json.loads((ROOT / SLUG / "data/registry.json").read_text())
    target = ROOT / "catalogue.json"
    previous = json.loads(target.read_text()) if target.exists() else {}
    records = discover_one(args.repo, exceptions, previous) if args.repo else discover(repositories(), exceptions, previous)
    if args.expect_version:
        entry = next((e for e in records if e["repo"] == args.repo), None)
        if not entry or entry["version"] != args.expect_version:
            raise ValueError("Published release does not match expected version " + args.expect_version)
    if previous.get("plugins") == records or args.check:
        print("Selected release metadata verified; no catalogue file written.")
        return
    candidate = {"schema": 1, "published_at": datetime.datetime.now(datetime.timezone.utc).isoformat(), "plugins": records}
    temp = target.with_suffix(".tmp")
    temp.write_text(json.dumps(candidate, indent=2) + "\n")
    temp.replace(target)


if __name__ == "__main__":
    main()
