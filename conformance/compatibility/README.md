# N / N−1 client compatibility

`python3 conformance/compatibility/run.py --matrix conformance/compatibility/matrix.json`
runs the PHP and Dart test clients on the client vectors and on every HTTP
transcript command body. Each run uses its generation's codec and native
envelope registry. `currentOnlySchemas` lists the envelopes added since P2
and is checked against the two generations' registries (D59, D61).

Compared values are wire bytes, digest, error code and money totals. Language
class names are not part of the result. Unknown command fields are refused.
Current-only envelopes must be refused by P2. The feed page and payment-status
vectors cover envelopes introduced after P2.
