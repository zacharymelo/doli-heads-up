# Development

## Local Dolibarr

```bash
docker compose up -d
```

This starts Dolibarr 24 and MariaDB on http://localhost:8141, with `module/` mounted as `htdocs/custom/headsup`, so edits show up on reload. The credentials in `docker-compose.yml` are throwaway defaults for this local container only. The port is bound to `127.0.0.1`.

Then enable Heads-up in **Home → Setup → Modules**. Turn the module off and on again after changing menus, rights or constants in the descriptor.

## Building the zip

```bash
./build.sh
```

This writes `headsup-X.Y.Z.zip`, with `headsup/` at its top level and this README inside. The version is read from `module/core/modules/modHeadsup.class.php`. Zips are git-ignored.

## Code rules

Heads-up follows Dolibarr's own module rules, from the [`.agents/`](https://github.com/Dolibarr/dolibarr/tree/develop/.agents) folder on the develop branch:

- Use Dolibarr wrappers instead of PHP natives: `dol_now()`, `getDolGlobalString/Int()`, `isModEnabled()`, `$user->hasRight()` and `GETPOST*()`.
- Put `newToken()` on forms and on links that change something.
- Escape output with `dol_escape_htmltag()` / `dolPrintHTML*()`.
- In SQL, use `$db->escape()` or `(int)` casts and `entity IN (getEntity())`.
- Edit only `langs/en_US`.
- Indent with tabs and follow PSR-12. Give properties, arguments and return values full PHPDoc.

## Static analysis

Before a release, run php lint, phpcs (Dolibarr's develop ruleset), phpstan and phan on the changed files. Report any findings that already existed in files the change didn't touch, and don't fix them in an unrelated change.

## Releases

1. Bump `$this->version` in `module/core/modules/modHeadsup.class.php`.
2. Add a section to [CHANGELOG.md](../CHANGELOG.md).
3. Commit with the title `vX.Y.Z — summary`.
4. Run `./build.sh` and attach the zip to a GitHub release tagged `vX.Y.Z`.
