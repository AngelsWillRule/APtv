# APtv security policy

## Supported versions

OpenAP has not published its first supported release. The current `main` branch
contains pre-release software and does not yet receive a formal security
support guarantee.

After the first release, this file will identify supported versions and the
security-update policy.

## Reporting a vulnerability

Do not disclose suspected vulnerabilities in a public issue, discussion, pull
request or chat.

Use GitHub's private vulnerability reporting channel:

https://github.com/AngelsWillRule/APtv/security/advisories/new

Include, when possible:

1. the affected version, commit and platform;
2. the vulnerability type and likely impact;
3. affected source paths and relevant configuration;
4. minimal reproduction steps;
5. proof-of-concept material, if safe to share privately;
6. suggested mitigation or fix.

Remove unrelated credentials, SSIDs, MAC addresses, public addresses, hostnames
and private keys. Do not test against systems or networks you do not own or
have explicit permission to assess.

The project will acknowledge the private report and coordinate validation,
remediation and disclosure according to severity and maintainer availability.
No fixed response deadline is promised before the first supported release.

Vulnerabilities in third-party components should also be reported to their
respective maintainers.
