# Airlock: decisions

The technical and functional decisions behind Airlock, one entry each: the context, the choice, what was rejected, and
what follows from it. How the result works: [design.md](design.md); where it lives in the code:
[architecture.md](architecture.md).

## 1. One server asks, not each client

- **Context.** Each client answers "may the agent run this?" its own way, a list of allowed prefixes here, an "allow for
  this session" there, and none of it travels to another client.
- **Choice.** The question moves into one MCP server every client can use. It asks through MCP elicitation, a form in
  the user's own client.
- **Rejected.** Relying on each client's permission settings: they differ, and they cannot see what a command line
  really runs.
- **Consequence.** Airlock sees only what comes through `run_command`: an agent keeping its client's own shell tool can
  run anything without it. `airlock connect` removes that tool where the client's files allow it, and says what to do
  by hand where they do not (Cursor).

## 2. The form's answer travels in a signed state, bound to one command

- **Context.** Under MCP 2026-07-28 the tool returns `input_required` and the client calls again with the answer and
  the state it was given. That state comes back through the client, so it is attacker-controlled.
- **Choice.** The MCP bundle signs the state; it names the fingerprint of the exact command and a one-use token.
- **Rejected.** Trusting the answer the retry carries as it comes.
- **Consequence.** A forged, tampered, replayed or mismatched answer runs nothing; an answer for `make test` cannot run
  anything else.

## 3. A shell parser, not patterns

- **Context.** `make test; rm -rf ~` starts with `make test`. A pattern on the text cannot tell what a line runs.
- **Choice.** `App\Shell` reads the line the way bash does: simple commands, pipes, `&&`, `;`, redirections, quotes,
  wrappers. What it cannot read whole (a substitution, a here-document, a program named by a variable) is *opaque*,
  and an opaque line is asked every time.
- **Rejected.** Regular expressions or prefix matching on the command string.
- **Consequence.** The parser is tested against bash itself: random lines with fixed seeds, run in bash, and every
  command bash runs must be one the parser read.

## 4. A grant covers a prefix or an exact command

- **Context.** "Always allow `make test`" should cover `make test --verbose`, but "always allow `git status`" must not
  cover `git push`.
- **Choice.** A grant covers its program and first argument followed by anything (`make test …`), or exactly the
  command when its first word after the program does not say what it will do (git, wrappers, curl…). A command asked
  every time (`sudo`, a recursive delete, `git push`…) offers only *once* or *refuse*: no grant covers it.
- **Rejected.** Grants on the program alone, or on the whole line.
- **Consequence.** A line runs without asking only when every simple command in it is covered.

## 5. A grant holds only while the files behind it are unchanged

- **Context.** A granted `make test` runs whatever the Makefile says, and the agent can edit the Makefile.
- **Choice.** A grant records the project files that decide what its command does (the Makefile, `package.json`,
  `composer.json`, the script run) with a SHA-256 each. Once one changes, the command is asked again and the form names
  the file. A line that can change such a file before the command reading it starts is never covered.
- **Rejected.** Grants on the words alone.
- **Consequence.** `grants.yaml` gained a `sources` list per grant. Not followed: what those files load in turn
  (`include`, a script calling another) and code given inline (`bash -c '…'`); design.md says so.

## 6. The policy fails closed

- **Context.** Refusals live in files a user edits: a global one, one per project, and a committed `.airlock.yaml`.
- **Choice.** A file that cannot be read stops every command until it is fixed. The committed `.airlock.yaml`, which
  the agent could edit, can only add refusals.
- **Rejected.** Skipping a broken file and going on with the rest.
- **Consequence.** A typo blocks work, visibly, rather than lifting a refusal silently. `airlock doctor` points at it.

## 7. The OS's own sandbox, per command

- **Context.** A command must run with the project, the user's tools and the package caches, yet write nowhere else and
  read none of the user's keys or Airlock's own files.
- **Choice.** bubblewrap on Linux, `sandbox-exec` on macOS, started for each command. Where neither can run, Airlock
  says why and asks about every command, with no grant covering anything.
- **Rejected.** Running commands in a container: the command needs the user's own toolchain on the user's own files.
  Docker is offered only as a way to run Airlock itself.
- **Consequence.** No Windows. `/tmp` and the runtime directory are private per command, `.git/hooks` and `.git/config`
  are read-only, and the SSH agent goes only to a command the user has just allowed.

## 8. Registries-only network, through a proxy written in PHP

- **Context.** A sandboxed command with the whole network can send what it reads anywhere; with none, `composer
  install` fails.
- **Choice.** By default a command reaches the package registries and the hosts `network.yaml` allows, through a proxy
  Airlock starts for it (`airlock network-proxy`), on PHP streams, with no new extension or dependency. It refuses a
  name that resolves to a non-public address. The default switched rather than a mode being added: the protection
  counts only if it is on without the user knowing.
- **Rejected.** Reading TLS to filter paths, which needs a certificate authority every tool would trust; leaving the
  network open by default.
- **Consequence.** A host on the list that takes writes with a token still receives them (`npm publish`;
  `api.github.com`, which Composer needs). `open` brings back the full network, `strict` cuts it. Details:
  [network.md](network.md).

## 9. A command reaching a git or SSH remote gets the open network

- **Context.** `git push`, `fetch`, `clone`, `ssh`, `scp`, `rsync` talk to hosts no list can know in advance.
- **Choice.** They run with the network open and are asked as reaching another machine; a line joining a remote to
  other parts is asked too, since the remote's network would reach them.
- **Rejected for now.** Opening only the remote's host, which needs SSH through the proxy (`ProxyCommand`); it can come
  later.
- **Consequence.** The form says the command talks to another machine and can use the SSH agent.

## 10. Models read, they never decide

- **Context.** The output a command returns is where an injection reaches the agent, and a model can spot one. But the
  model reads attacker-controlled text and can be fooled.
- **Choice.** The output always goes back masked and between tags it cannot close, marked as data. A model, through
  Symfony AI's structured output, adds a flag (`clean` or `injection`); a second one writes one line on what a risky
  command would do, under the rule, never instead of it. Both are optional, with no default API, key or model in the
  code.
- **Rejected.** A model allowing or refusing commands; NVIDIA's moderation models, which missed an injected README every
  time; a classifier only served with paid credits.
- **Consequence.** A slow, rate-limited or missing model gives "not checked", saying why, and the command still runs.
  The key is set in a terminal by `airlock configure`, never through the agent.

## 11. One static binary per platform

- **Context.** Asking the user for PHP 8.5, or Docker, stands between them and a first run.
- **Choice.** A PHAR of Airlock and its production dependencies, appended to a static PHP 8.5 runtime built with
  static-php-cli, for Linux (x86-64, arm64) and macOS. Each binary is smoke-tested on its platform as a client starts
  it, and attested with its build provenance and an SBOM.
- **Rejected.** Shipping only a Composer install or a Docker image.
- **Consequence.** The macOS binary is not signed or notarized yet: Gatekeeper warns. It is next on the roadmap.

## 12. Local only: stdio, a local audit log, nothing sent

- **Context.** A tool that runs commands on the user's machine is worth attacking, and its log says what the user did.
- **Choice.** The server speaks stdio only, one process per client; the HTTP transport is off. Every decision is a JSON
  line in Airlock's own directory, readable by its owner only and out of every command's reach, served to the agent as
  `airlock://audit` for the current project only. Nothing leaves the machine but what the user set up: the model API
  they configured, their own `gh`, and what the commands themselves reach.
- **Rejected.** An HTTP endpoint: a tool that runs commands has no business on one.
- **Consequence.** Two clients share one Airlock's files under a `symfony/lock` lock; the log rotates past a size.
