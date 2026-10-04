# Development

Follow https://github.com/cchatterton/codex-standards, particularly the WordPress plugin, GitHub update, branding and general development standards.

Keep the Techn and AlphaSys controller engines behaviourally aligned while preserving separate branding, registry identities, namespaces and state. Never cross-populate catalogues. Keep all normal-page metadata reads free of HTTP, including cold caches. Discover released same-brand packages by default; keep the registry for documented exceptions and reviewed legacy/readiness handling only. Expand legacy callback hashes only after reviewing published packages. Checks are strictly manual; never schedule discovery or publishing scans.

For distributable changes update plugin/header/readme/changelog versions, build and commit the root release ZIP, publish the matching tagged GitHub asset, then verify and publish catalogue metadata. Tests that install plugins or alter options run only on disposable WordPress installations. Do not install these tests on customer sites.
