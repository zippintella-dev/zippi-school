# Where this project lives, and why it must stay here

**Location: `~/zippi-school`**

```bash
cd ~/zippi-school
php artisan serve
```

Then open <http://localhost:8000> and sign in as `ops@zippi.in` / `password`.

## ⚠ Do not move this into `~/Desktop` or `~/Documents`

Both are **iCloud-synced** on this machine. This project carries roughly 10,000
files under `vendor/`, and iCloud stalls the rapid small-file reads that
Composer's class loader and PHPUnit perform at startup.

The failure mode is the dangerous kind: **not a clean error**. The app hangs with
no output, and eventually dies with

```
Maximum execution time exceeded at vendor/composer/ClassLoader.php
```

which looks like a code bug and is not one.

Measured on this machine — identical code, identical PHP:

| Location | Test suite |
|---|---|
| `~/Desktop/School Ets/zippi-school` | hangs indefinitely |
| `~/zippi-school` | **3.9 seconds** |

That is why the project was moved out of `~/Desktop/School Ets` and why it must
stay outside both synced folders.

## Related: check your other Laravel projects

`~/Documents/zippi` is in the same synced tree and is already showing iCloud
conflict-copy damage — files macOS duplicated with a `" 2"` suffix because it
could not reconcile two versions:

```
.git 2          ← a conflicted copy of the repository metadata
bootstrap 2
artisan 2
tests 2
vite.config 2.js
.well-known 2
```

`.git 2` is the one that matters — that is how repositories get silently
corrupted. Worth running `git status` and `git fsck` there, and moving that
project somewhere outside iCloud too.

## History

This file originally sat in `~/Desktop/School Ets` as a pointer to the app after
it was moved out. That folder has since been retired: the specs moved to
`docs/`, the Claude Code settings to `.claude/`, and the pointer became this
note. The same warning appears in the project [`README.md`](../README.md).
