# Releasing

All five GOAL API SDKs share one version number, so `1.4.0` means the same surface in
every language. Bump this repo in step with the others.

```bash
# 1. Bump the version in `src/Transport.php` (the `VERSION` constant).
# 2. Add the CHANGELOG.md entry, then commit.
git commit -am "release 1.1.0"
git push

# 3. Tag it. The publish workflow refuses to run if the tag and the
#    committed version disagree.
git tag v1.1.0
git push --tags
```

To rehearse, run the `publish` workflow manually with `dry_run: true`: it builds,
validates and packs, then stops before uploading.

## One-time setup

### Packagist

Packagist builds from the git tags in this repository, so there is nothing to upload.
Because `composer.json` sits at the repo root, this works with no extra configuration.

1. Submit `https://github.com/goal-api/goal-api-php` on packagist.org once.
2. Either add the GitHub service hook, or set the two secrets below and the workflow
   will call the update API itself.

`composer.json` deliberately has no `version` field: Packagist takes it from the tag, and
a hardcoded one goes stale every release.

## Package name

> The registry name in this repo is a placeholder pending confirmation. Check you own the
> namespace before the first publish; renaming after release is disruptive.

## Secrets

| Name | Environment | Needed for |
|---|---|---|
| `GOAL_API_KEY` | `live-api` | The live tests. They skip without it. |
| `PACKAGIST_USERNAME` / `PACKAGIST_TOKEN` | `packagist` | Optional. Only skips the polling delay. |
