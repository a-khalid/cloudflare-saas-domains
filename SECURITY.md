# Security Policy

## Supported versions

Only the latest release receives security fixes.

| Version | Supported |
|---|---|
| 0.1.x | Yes |

## Reporting a vulnerability

Please do not open a public issue. Report it through GitHub's private vulnerability reporting: the **Report a vulnerability** button on this repository's Security tab.

Include what is affected, how to reproduce it, and the impact you expect. You can expect a first response within a week.

## Handling credentials

- The API token needs only the `SSL and Certificates Write` permission on the one zone. Do not use a Global API Key.
- Keep the token in your environment. Package exceptions do not include it, but check your own logging of HTTP clients.
- `.env.testing` holds live test credentials and is git-ignored. Do not commit it.

## Host header

`ResolveTenantByHostname` trusts the request's host. Only active domains resolve, but make sure your origin accepts traffic only from Cloudflare, otherwise anyone can reach it directly with any active merchant's hostname in the `Host` header.
