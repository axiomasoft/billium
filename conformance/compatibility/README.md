# N / N−1 client compatibility

`python3 conformance/compatibility/run.py --matrix conformance/compatibility/matrix.json`
runs the PHP and Dart test clients on the shared client vectors and on every
HTTP transcript command body. The prior generation is the pinned archive, with
its negotiated schema set equal to that archive's envelopes minus
`currentOnlySchemas` (D59, D61).

Compared values are wire bytes, digest, error code and money totals. Language
class names are not part of the result. Unknown command fields are refused.
`billium.event.v2` is not delivered to the prior pin as a success (D63).
