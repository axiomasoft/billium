# Billium Server

Laravel HTTP delivery for Billium Core. The host chooses a supported module composition and PostgreSQL connection, supplies the server authentication and authorization ports, and runs the selected core migration sets explicitly. The same PHP application APIs serve embedded hosts and HTTP callers. (D18, D55, D61, D62; owners host and core.)

The ledger-only profile accepts authorized external observations and serves ledger queries. The remote Billium profile delegates command and recovery requests to core execution. `/billium/live` reports HTTP liveness and `/billium/ready` checks configuration and installed schema compatibility. (D55, D58, D61, D63; owners server, ledger and execution.)
