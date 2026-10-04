# Enable catalogue automation

The initial release and catalogue are published. The current GitHub CLI credential has repository access but cannot create workflow files, so these templates are not active.

An authorised repository maintainer can enable them by moving `docs/workflow-templates/catalogue.yml` and `lint.yml` into `.github/workflows/`, then committing and pushing with a credential permitted to manage workflows. A GitHub CLI user can request that scope with `gh auth refresh -h github.com -s workflow` and complete GitHub's authorisation flow themselves.

Dispatch **Verify and publish catalogue** once, check the run succeeds, and confirm the catalogue commit. The workflow uses the repository's built-in GITHUB_TOKEN; no site-side token is needed. Ensure repository Actions settings allow the job's requested contents write permission. If branch protection requires pull requests, adjust the publication step to your approved PR workflow rather than bypassing protection.

Until then, `python3 scripts/publish-catalogue.py` verifies all released assets locally; review, commit and push catalogue.json after each feature-plugin release. Site discovery reads that snapshot only after an explicit manual check. The template has no schedule; dispatch it explicitly after releases.
