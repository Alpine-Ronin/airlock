# Security

Airlock stands between an agent and your machine, so a way around it is a vulnerability. Report it privately, not
in an issue.

## Reporting

Open a private report on GitHub: [Report a vulnerability](https://github.com/alpine-ronin/airlock/security/advisories/new).
Say which version (`airlock --version`, or `git rev-parse HEAD` in the clone when you run it from source or
Docker), on which system, with which client, and the shortest steps that show it.
You get an answer within seven days, and the fix is credited to you unless you would rather not.

Only the latest release is fixed: update before reporting. With a binary, run the
[installer](README.md#with-a-binary-no-php-no-docker) again; from source, `git pull` and `composer install` in the
clone; with Docker, `git pull` in the clone and build the image again. The installer has no Intel Mac binary: there,
run Airlock from source or Docker.

## What counts

Airlock protects a user from what their agent read. A vulnerability is a way for the agent, or for text it read, to:

- run a command the user should have been asked about without asking them, or one they refused (a command
  harmless to run, or covered by a grant, runs without asking by design);
- have a grant cover more than the form said it would (a grant for `make test` covering `make test; rm -rf ~`);
- write outside the project, `/tmp` and package caches from inside the sandbox, read the user's keys or Airlock's
  own files, or reach the network with `strict`;
- forge or replay an approval;
- read a secret Airlock should have masked in an output, or end the tags that mark an output as data;
- change the user's grants, policy, models or audit log;
- leave a client's own shell tool on after `airlock connect` said it turned it off.

## What does not

- A model misjudging an output or a command. The output check and the plain-words line are advice: a missed
  injection still reaches the agent marked as data, with its secrets masked, and the user still decides.
- What the user does themselves: Airlock is not a boundary against its own user.
- A client's own tools the user kept on, or a client that runs commands before asking Airlock.
- Windows, where Airlock does not run.

[docs/design.md](docs/design.md) describes what happens to a command, step by step.
