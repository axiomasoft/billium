# Prior codec and client snapshot

The archives named by `matrix.json` are the first N−1 pin. They denote the P2
contracts artifact at spec revision `2026-10` (D9, D59). They are not a
Packagist or pub.dev release.

`run.py` checks the recorded sha256 and refuses a symlink. It does not rebuild
the archives from the working tree. Recapturing them is a separate `--pin`
maintenance action and is not part of acceptance.
