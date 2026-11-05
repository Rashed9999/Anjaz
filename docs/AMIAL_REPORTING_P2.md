# AMIAL Reporting Center — P2 Operations & Identity

## Scope

P2 completes operational reporting that is useful to management but must not weaken privacy or invent business policy.

Current P2 surfaces:

- Email / OTP delivery analytics — aggregate only.
- Authentication security analytics — aggregate only.
- Support operations and resolution SLA — ticket truth is always available; SLA compliance is calculated only after an approved policy is configured.

## Privacy boundary

The Reporting Center must not expose:

- email address or phone number;
- login identifier, even masked identifiers;
- IP addresses or user-agent strings;
- OTP values, OTP hashes, verification token hashes;
- provider message IDs, provider error bodies or secret configuration values.

Authentication reporting may count repeated failure sources, but it returns only the aggregate count. Email reporting exposes only provider-readiness booleans, never API keys or webhook secrets.

## Support SLA policy

The application deliberately ships without invented SLA targets. Operations management must approve the resolution target for every priority before the catalog can mark `support_sla` as `ready`.

Configure these production environment values in **minutes**:

```env
AMIAL_SUPPORT_SLA_URGENT_MINUTES=
AMIAL_SUPPORT_SLA_HIGH_MINUTES=
AMIAL_SUPPORT_SLA_NORMAL_MINUTES=
AMIAL_SUPPORT_SLA_LOW_MINUTES=
```

Rules:

1. Every value must be a positive integer.
2. All four priorities must be configured; a partial policy keeps the report `partial`.
3. The current clock is wall-clock time from ticket creation to `resolved_at`, or to current server time while unresolved.
4. `waiting_customer` does **not** pause the clock in this version because no approved pause policy exists.
5. No first-response SLA is claimed because the support schema has no authoritative first-response timestamp yet.

Changing policy changes future report evaluation; it does not rewrite ticket history.

## Readiness truth

`email_otp` and `auth_security` are implementation-ready when their source tables exist. `support_sla` becomes catalog-ready only when the four policy targets above are configured. A CI success proves the code gates passed; it does not prove the commit has been deployed to the production server.
