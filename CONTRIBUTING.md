# Contributing to APtv

OpenAP is preparing its first public release. Contributions should preserve
network recoverability, least-privilege helpers, hardware-based interface
detection and accurate platform support claims.

## Before opening a change

1. Search existing issues in `AngelsWillRule/APtv`.
2. Open an issue describing the problem, affected platform and proposed scope.
3. Remove SSIDs, MAC addresses, public IPs, usernames, hostnames, credentials
   and private infrastructure details from diagnostic output.
4. For security vulnerabilities, follow `SECURITY.md` instead of opening a
   public issue.

## Development workflow

1. Fork the repository and create a focused branch such as `fix/description`
   or `feature/description`.
2. Keep unrelated formatting and generated files out of the change.
3. Preserve copyright, license and provenance notices in derived files.
4. Run PHP, shell and JavaScript syntax checks relevant to the change.
5. Test installer or networking changes through dry run first.
6. Document hardware, operating system, kernel and exact OpenAP commit used for
   runtime validation.
7. Open a pull request describing behavior, risks, verification and rollback.

Run the same validation suite used by GitHub Actions before opening a pull
request:

```bash
tests/ci-checks.sh
```

It requires Bash, Git, gettext, Node.js, PHP CLI and Python 3. The suite checks
source syntax, translation catalogs, repository hygiene and the installer dry
run without changing the host.

## Networking changes

Changes to addressing, DHCP, DNS, hostapd, routes or firewall rules must be
idempotent and must not assume fixed interface names. They require validation
of dashboard reachability, hotspot service, client DHCP/DNS, forwarding and
mode transitions where applicable.

Do not describe a platform as supported based only on package compatibility or
a dry run. A support claim requires a checksum-linked clean installation on
that platform.

## Style

- Use clear English for public documentation and messages.
- Follow the existing PHP and shell style in touched files.
- Prefer narrow privileged helpers over adding broad sudo permissions.
- Never commit credentials, installed-system configuration, logs, archives or
  private lab notes.
