# Prior codec and client snapshot

The archives named by `matrix.json` contain the PHP and Dart contracts packages
from the P2 closure commit `a2e52060fa75d57e5149755f20e12796a3a3f94d`
at spec revision `2026-10` (D9, D59). The source predates the P8 HTTP test
clients. The matrix and install smoke use the same test client code with
each generation's codec.

`run.py` checks the recorded SHA-256 hashes and refuses symlinks. The separate
`--pin` maintenance action reads both package trees from that commit through
`git archive` and records the hashes of the resulting ZIPs.
