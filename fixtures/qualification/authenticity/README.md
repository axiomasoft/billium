# Simulated callback authenticity fixture

`callback.json` signs a synthetic callback transcript. The signed object contains the installation, seller, environment, merchant account, intended receiver, event reference, and body. Canonicalize the entire object using RFC 8785 JSON, encode the result as UTF-8, and calculate HMAC-SHA-256 with the decoded `keyHex`. The expected canonical text and lowercase hexadecimal digest are fixed. The fixture key is public test data.

Each case replaces the top-level transcript fields in `changes`, if present. A case may also supply its own key or signature. Mutated scope, account, audience, event reference, body, key, and signature must all fail verification. Set `BILLIUM_AUTHENTICITY_WRONG_CONTROL=1` for a deliberate negative run that expects the malformed signature to verify. Both test suites must report a failure in the `bad-signature-control` case.

PHP and Dart compute the signature independently using their existing crypto implementations. This fixture verifies agreement on transcript bytes and signature handling. Provider qualification and production callback authentication require the provider's original protocol, keys and evidence.
