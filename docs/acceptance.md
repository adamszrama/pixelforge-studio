# Live acceptance checklist

Use only this deployment's provider API key. Record IDs and expected costs without copying credentials into reports.

1. Register and add demo credits. Refresh to confirm the wallet is durable.
2. Submit a low-quality `[succeed] [duplicate]` prompt. Verify the UI moves through queued/processing to succeeded, displays an image, and records exactly one debit despite duplicate webhook deliveries.
3. Resend that success event from the provider dashboard. The endpoint should return 204; balance and ledger count must not change.
4. Resend an older processing event. The image must remain succeeded, with unchanged cost.
5. Submit `[fail]`. Verify failed status, zero provider/user cost and full release of reserved funds.
6. Submit `[blocked]`. Verify a readable rejection without any provider generation charge.
7. Submit `[slow] [succeed]`, then leave and return. Status should remain durable while processing.
8. Submit `[overloaded]`. Verify delayed bounded retries and eventually a free failure, without a tight retry loop.
9. Temporarily use a deliberately unreachable webhook URL on a disposable local key. A successful request must settle through polling. Restore the webhook afterward.
10. Review the operator report for the completion date in UTC. Local provider cost should match `GET /v1/usage` exactly; user charge should be 1.25 times provider cost.
11. Try to generate without sufficient available credits. No generation should be submitted. Multiple simultaneous requests cannot reserve the same funds twice.
12. Check the interface on a narrow viewport. Prices, errors, reservation explanations and history remain readable.

Automated tests also cover an early terminal event before the submission response and concurrent webhook/poll settlement, which are difficult to reproduce reliably by hand.
