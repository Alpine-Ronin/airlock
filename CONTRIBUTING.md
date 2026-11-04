# Contributing

Thanks for helping. A vulnerability goes to [SECURITY.md](SECURITY.md), never to an issue. A bug, or a client that
behaves differently from what the README says, goes to an [issue](https://github.com/hbenabdallah/airlock/issues/new/choose).
For a change bigger than a fix, open an issue first, so that we agree on it before you write it.

## Setting up

You need PHP 8.5 with the extensions `composer.json` requires, Composer, and on Linux bubblewrap
(`apt install bubblewrap`). Then:

```bash
git clone https://github.com/hbenabdallah/airlock.git && cd airlock
composer install
composer check
```

`composer check` runs what CI runs: PHP CS Fixer, Rector, PHPStan at its highest level with strict rules, and
PHPUnit. The tests never reach the network. On Ubuntu 24.04, bubblewrap needs
`sudo sysctl -w kernel.apparmor_restrict_unprivileged_userns=0`; in a container, it needs
`--security-opt seccomp=unconfined --security-opt apparmor=unconfined --security-opt systempaths=unconfined`.

Run the server from the clone with `php bin/console mcp:server`, and connect a client to it with
`php bin/console connect <client>`.

## Making a change

- Branch from an up-to-date `main`: `feat/<what-it-adds>` or `fix/<what-it-fixes>`, in a few lowercase words.
- Commit in small steps, each passing `composer check`, with a one-line message saying what it does.
- A bug fix starts with a test that fails without it. A change of behaviour comes with a test that uses it the way
  a caller would.
- Run Rector, then the formatter (`vendor/bin/rector process && composer cs:fix`), and keep what they change. Never
  add a PHPStan baseline or an ignore: fix the code.
- Try the change in a real client before opening the pull request, and say in it how you did.

[AGENTS.md](AGENTS.md) and [PHP-RULES.md](PHP-RULES.md) hold the conventions in full, for people and coding agents
alike. [docs/design.md](docs/design.md) explains how Airlock works.

## License

What you contribute is published under the [MIT license](LICENSE), like the rest of Airlock.
