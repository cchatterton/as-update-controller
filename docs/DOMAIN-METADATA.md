# Plugin domain metadata

Domain availability belongs to the feature plugin's main PHP header, not hard-coded controller rules. Use these optional fields:

```text
Allowed Domains: alphasys.com.au
Allow Subdomains: true
```

`Allowed Domains` is a comma-separated list of hostnames, with no scheme, path, port or wildcard. The publisher normalises hostnames to lowercase and rejects malformed values. An absent/empty domain header means unrestricted availability. `Allow Subdomains` accepts `true` or `false` (default false); matching descendants requires a dot boundary, so `notalphasys.com.au` and `alphasys.com.au.example.org` never match. The exact configured hostname `localhost`, including URLs with a port, always bypasses domain availability restrictions and displays every approved catalogue plugin. This exception does not extend to arbitrary `.localhost`, `.local`, loopback IPs or a claimed request Host header.

The catalogue publisher reads these headers from the verified release ZIP and publishes `allowed_domains` and `include_subdomains` alongside its checksum. Controllers validate and cache that metadata. Future restriction changes require a feature-plugin release and catalogue publication, not a controller release. Before the first catalogue check, installed plugin headers provide the local fallback; uninstalled entries with unknown availability stay hidden except on localhost. Ordinary admin views perform no remote metadata lookups.

Match the configured home URL on single-site and the network home URL on multisite. A mixed-domain network is governed by its network domain; a qualifying member site alone does not authorise network-wide installation. Apply restrictions to catalogue cards, update projections, managed installation/activation and package downloads. Localhost bypasses only domain availability, not permissions, compatibility, dependency checks or package verification. These headers govern controller distribution; they do not disable plugin runtime outside the controller. Public GitHub repositories/assets remain public and this is not licensing or download authentication.

New custom plugins must be explicitly approved in the publisher's registry, with exact repository, author, basename, ZIP identity and beta status. Publish their verified catalogue entries to the fixed brand-specific HTTPS feed; controllers accept new same-brand identities without a controller release. Do not enumerate every repository by author. Validate identities and paths, reject collisions and cross-brand entries, and retain existing identity pins. Remote metadata must never introduce executable legacy callback trust; that remains audited and bundled.
