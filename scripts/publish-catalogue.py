#!/usr/bin/env python3
"""Verify approved GitHub release assets before atomically publishing metadata.
Requires authenticated gh CLI. Never expands registry trust or legacy-code hashes.
"""
import argparse, datetime, hashlib, json, re, subprocess, tempfile, zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SLUG = "as-update-controller"
BRAND = "AlphaSys"

def header(text, key, default=""):
    match = re.search(r"^\s*\*?\s*" + re.escape(key) + r":\s*(.+)$", text, re.M)
    return match.group(1).strip() if match else default

def collect(entry):
    if type(entry.get("beta")) is not bool:
        raise ValueError("Registry entries require an explicit boolean beta status")
    domains = entry.get("allowed_domains", [])
    if not isinstance(domains, list) or any(not isinstance(d, str) or (d != "localhost" and not re.fullmatch(r"[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+", d)) for d in domains):
        raise ValueError("Domain allowlists require lower-case hostnames without schemes, paths or wildcards")
    if type(entry.get("include_subdomains", False)) is not bool:
        raise ValueError("include_subdomains must be a boolean")
    if entry["owner"] != "cchatterton" or entry["author"] != BRAND:
        raise ValueError("Registry ownership is outside this controller")
    release = json.loads(subprocess.check_output(["gh", "api", "repos/" + entry["owner"] + "/" + entry["repo"] + "/releases/latest"]))
    if release.get("draft") or release.get("prerelease"):
        raise ValueError("Only published stable releases are supported")
    assets = [a for a in release["assets"] if a["name"] == entry["asset"]]
    if len(assets) != 1 or assets[0]["size"] > 67108864:
        raise ValueError("Missing, ambiguous or oversized release asset: " + entry["id"])
    with tempfile.TemporaryDirectory() as temp:
        subprocess.run(["gh", "release", "download", release["tag_name"], "--repo", entry["owner"] + "/" + entry["repo"], "--pattern", entry["asset"], "--dir", temp], check=True)
        path = Path(temp) / entry["asset"]
        if path.stat().st_size != assets[0]["size"]:
            raise ValueError("Asset size changed")
        with zipfile.ZipFile(path) as archive:
            names = archive.namelist()
            if any(name.startswith("/") or ".." in name.split("/") or not name.startswith(entry["slug"] + "/") for name in names):
                raise ValueError("Unexpected package root or unsafe archive path")
            info = archive.getinfo(entry["file"])
            if info.file_size > 2097152:
                raise ValueError("Oversized plugin main file")
            text = archive.read(entry["file"]).decode("utf-8", errors="replace")[:8192]
        version = header(text, "Version")
        author = header(text, "Author")
        if author.casefold() != entry.get("author_header", BRAND).casefold():
            raise ValueError("Release author differs from approved brand: " + entry["id"])
        uri = header(text, "Update URI")
        if uri and uri.rstrip("/") != "https://github.com/" + entry["owner"] + "/" + entry["repo"]:
            raise ValueError("Release Update URI differs from approved repository")
        if not header(text, "Plugin Name") or release["tag_name"] not in (version, "v" + version, "V" + version):
            raise ValueError("Plugin header/tag mismatch: " + entry["id"])
        record = {k: v for k, v in entry.items() if k not in ("legacy", "author_header")}
        record.update(version=version, tag=release["tag_name"], description=header(text, "Description", entry["description"]), requires=header(text, "Requires at least", "6.0"), requires_php=header(text, "Requires PHP", "8.1"), dependencies=[x.strip() for x in header(text, "Requires Plugins").split(",") if x.strip()], controller_api=int(header(text, BRAND + " Controller API", "1" if entry["id"] == SLUG else "0")), body=release.get("body") or "See the published release notes.", sha256=hashlib.sha256(path.read_bytes()).hexdigest())
        for key in ("version", "requires", "requires_php"):
            if not re.fullmatch(r"\d+\.\d+(?:\.\d+){0,2}", record[key]):
                raise ValueError("Invalid " + key + ": " + entry["id"])
        if any(not re.fullmatch(r"[a-z0-9-]+", d) for d in record["dependencies"]):
            raise ValueError("Invalid dependency")
        print(entry["id"] + " " + version + " verified")
        return record

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true", help="Verify releases without writing the catalogue")
    args = parser.parse_args()
    registry = json.loads((ROOT / SLUG / "data/registry.json").read_text())
    records = [collect(registry[key]) for key in sorted(registry)]
    target = ROOT / "catalogue.json"
    previous = json.loads(target.read_text()) if target.exists() else {}
    if previous.get("plugins") == records or args.check:
        print("All release assets verified; catalogue unchanged.")
        return
    candidate = {"schema": 1, "published_at": datetime.datetime.now(datetime.timezone.utc).isoformat(), "plugins": records}
    temp = target.with_suffix(".tmp")
    temp.write_text(json.dumps(candidate, indent=2) + "\n")
    temp.replace(target)

if __name__ == "__main__":
    main()
