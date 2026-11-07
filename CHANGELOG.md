# Changelog

What changed in each release, newest first. Versions follow [semantic versioning](https://semver.org): until 1.0, a
minor version may change the configuration and file formats.

## 0.5.0 (2026-11-07)

### Added

- `airlock grants --preset php|node|python` allows a kind of project's usual commands always, once the user agrees to
  the list: tests, linters and static analysis, never an install, a delete, a push or a download; each bound to its
  files like any grant. `airlock doctor` suggests the preset a project's files point to while nothing is allowed (#71).
- `airlock configure --check --samples <file>` measures the output check on a file of outputs, injected or clean: how
  many injections it flags, how many clean outputs it flags too. `tests/Fixtures/injection/` holds the public corpora,
  and the README the results (#68).

### Changed

- `airlock doctor` warns when commands can reach the network, as they do by default (`AIRLOCK_SANDBOX=auto`): one an
  injection slipped in can send what the project holds elsewhere. `AIRLOCK_SANDBOX=strict` cuts the network off (#67).
- A command sending a secret file's content, such as `curl -d @.env …` or `-F key=@id_rsa`, is asked about every time,
  as one naming the file already was. Airlock reads curl's options to find the files it sends (`-d`, `--data-urlencode`,
  `-F` with quoted names, lists and `headers=@`, `-T` with `{a,b}`, `-K`, `-H @`, `-b`), values attached to short
  options included (`-K.env`), and every file an upload pattern names, on top of the word check every command gets
  (#68, #72). An upload pattern naming more than 1,000 files, or one curl would not read, is asked about every time:
  its files are not checked one by one, so look at them before allowing it.
- A grant holds only while the project files deciding what its command does are unchanged: the Makefile for `make`,
  `package.json` for npm, pnpm, yarn and bun, `composer.json` for Composer, the script an interpreter or a path runs,
  also behind a wrapper or a `cd`. Once one changes, Airlock asks again and names it; `airlock grants` lists them. A
  line that can change such a file before the command reading it starts is asked about whatever the grants.
  `grants.yaml` gains a `sources` list per grant. A grant from an earlier version, of a command that reads such a
  file, is asked about once more (#65, #66).

## 0.4.0 (2026-11-07)

### Added

- `github_pr_checks` and `github_pr_comments`: the agent reads a pull request's CI checks, and its reviews and
  comments, line comments included. Airlock runs the user's GitHub CLI itself, outside the sandbox and without asking,
  and gives back what it read as a command's output: secrets masked, checked for prompt injection, marked as data,
  in the audit log as `read_from_github` (#57).
- `github_open_pr`: the agent opens a pull request from the current branch, with a title, a body, and optionally a base
  and as a draft. The user is asked every time and can only allow it once or refuse; the form shows the repository,
  the branch and its base, the title, whether it is a draft, and the body. Airlock builds the `gh pr create` call
  itself, never pushes (a branch not yet pushed is refused, saying to push it first), and gives back what gh printed as
  a command's output; in the audit log as `opened_on_github` or `refused_by_user` (#59).
- `airlock connect vscode` also writes an `airlock` agent, `.github/agents/airlock.agent.md`, whose tools leave out
  VS Code's terminal: picked in the chat, the agent runs commands only through Airlock. `--remove` takes it back.
  VS Code runs end to end (1.145) (#60).
- `airlock configure` shows how long each model took to calibrate, and `--fastest` keeps calibrating up to
  `--max-probes` models that answer and keeps the fastest that passes, instead of the first: the fastest fit for both
  checks, else the fastest for each (#61).
- `airlock doctor` and `airlock log` warn when the output check timed out on 3 or more of the project's last 20
  decisions, and suggest `airlock configure --fastest` (#61).

### Changed

- `airlock` and `airlock --help` say what Airlock is, give examples, and list only Airlock's commands: the framework's
  (`cache:*`, `debug:*`, `assets:install`...) are hidden, and still run when named (#61).

### Fixed

- `airlock doctor` called Airlock's files private when their directory, or a folder in it, could be listed but not
  entered, and said the directory did not exist yet when one above it could not be entered. Both are now a problem
  to fix (#62).

### Security

- A command got the GitHub CLI's token when the client started Airlock with one in its environment (`GH_TOKEN`,
  `GITHUB_TOKEN`, or their Enterprise forms): an approved command could use it or copy it. Commands no longer get
  these variables; only Airlock's own GitHub reads use them, and never the Enterprise ones, which gh sends to any host
  the repository names (#57).
- In a linked git worktree, whose `.git` is a file, a command could point that file at a git directory of its own,
  inside the project, with its own hooks and configuration: git then ran them outside the sandbox, and Airlock's own
  GitHub reads took the repository from them. The `.git` file is now read-only to a command, like the `hooks` and
  `config` of the git directory it points to and of the common one, wherever they are (#59).
- In any repository, a command could write `.git/commondir`, which tells git to take its hooks and configuration from
  another git directory, one of its own. `commondir` is now read-only to a command; where there is none, Airlock first
  makes one naming the git directory itself (`.`), which git reads as if there were none. The common directory an
  existing `commondir` names is kept like the git directory, and so is `config.worktree`, which git reads once
  `extensions.worktreeConfig` is set. A git directory a `.git` file or a `commondir` names stays kept even without its
  `HEAD`: a command could otherwise remove it in one run and plant a hook there in the next (#59).
- A command could move the project's `.git` directory aside, or a git directory a `.git` file points to, make a git
  directory of its own in its place and plant hooks that git then ran outside the sandbox. A command can now write in
  these directories but no longer move or remove them, nor the directories between them and the writable one holding
  them (#59).
- Where git reaches its hooks or configuration through a symbolic link in a directory a command may write to, such as
  `.git/hooks` linked to a folder of the repository, a command could replace the link with one of its own: the sandbox
  keeps a link's target read-only, never the link. Airlock now runs no command there and names the link to replace; to
  share hooks, set `core.hooksPath` instead (#59).

## 0.3.5 (2026-11-05)

### Added

- The install script checks where the binary was built when the GitHub CLI is logged in: a binary that this
  repository's release workflow did not build, from the tag of the version asked for, is not installed. Releases
  before 0.3.4 have no attestation, so theirs is not checked (#54).

### Fixed

- Since 0.3.3, a command whose output was checked in several parts, past 16 KB, failed with "Error while executing
  tool" when the model's answer for one part was lost and asked again: the command had run, but the agent got no
  output. Airlock now reads every part's answer before asking again for those lost (#55).

## 0.3.4 (2026-11-05)

### Added

- Each release binary comes with signed attestations of where it was built (this repository, its tag and commit, the
  release workflow) and of what it holds, a CycloneDX SBOM of its PHP packages published with it as
  `airlock.cdx.json`: `gh attestation verify` checks both (#46).

### Security

- The workflows pin each action to a commit and keep the token out of their checkouts, and only the job that publishes
  a release can write to the repository; Dependabot proposes the updates (#46).

## 0.3.3 (2026-11-05)

### Added

- A model whose answer did not come in time, or was lost to a server or network error, is asked once more before
  Airlock goes on without it: `AIRLOCK_AI_RETRIES` sets how many times. A rate limit, or an answer that came but cannot
  be used, is not asked again (#43).

### Fixed

- A model that did not answer within `AIRLOCK_AI_TIMEOUT` was reported as one that could not be reached, in the
  command's result, `airlock log` and `airlock configure`: it is now reported as not answering in time, and
  `airlock configure` no longer counts it as a model that answers (#43).
- A model that reasons before it answers, such as Mistral Large 4, ran out of room before its answer and was reported
  as one that could not be reached: the output check and the plain-words line now leave it room to answer (#44).

## 0.3.2 (2026-11-05)

### Security

- A grant could cover a command line that bash runs differently from how Airlock read it: `ls -l }`, `ls -l\`,
  `ls -l ''2>&1`, `> out ! ls -l` and `'{' ls -l` all ran unasked under a grant for `ls -l`, and `''A=1 ls` was read
  as `ls` with a variable set, though bash runs a program named `A=1`. Airlock now reads grouping words, assignments,
  a final backslash and quoted numbers before a redirection as bash does, and a test compares what it reads with what
  bash runs over a thousand random lines on every run (#41).
- Symfony is updated to 8.1.11, for the security fixes it published on 4 November 2026 (#41).

## 0.3.1 (2026-11-04)

### Fixed

- `airlock doctor` said a client was connected to no Airlock when it starts another one, such as an earlier install,
  and said nothing when the client starts this Airlock with its own shell tool back on: it now names both, the second
  as a problem (#38).
- A command lent the SSH agent, such as `git push` over SSH, could not check the server's host key, so ssh refused to
  connect: it now reads `~/.ssh/known_hosts`, read-only. The keys and the rest of `~/.ssh` stay hidden (#39).

### Security

- On macOS, a command run with a project that holds the user's keys or Airlock's own files, such as the home directory,
  could write to them: they were hidden from reading only. They are now out of reach for writing too, as on Linux
  (#39).
- A project that holds the home directory, Airlock's directory or Airlock's installation, or that sits inside a key
  store or Airlock's directory, now runs without a sandbox, so that every command is asked: there, a command could
  create a missing `~/.ssh/config` or Airlock's `.env.local`, change Airlock's own code, or rename the directory above
  a hidden one, on Linux and macOS alike. `airlock doctor` and the form say so (#39).
- Without a sandbox, a command covered by a grant ran without asking, with all the user's permissions. Every command
  is now asked when there is no sandbox, as the documentation said (#39).

## 0.3.0 (2026-11-04)

### Added

- `airlock grants` lists the commands allowed always in the project, and `--revoke` takes them back (#33).
- `airlock log` shows the project's last decisions in plain words (#33).
- `airlock doctor` says whether Airlock can protect the project, and what to do when it cannot (#35).
- The audit log moves aside at 10 MiB, and the five last logs moved aside are kept, so it never fills the disk;
  `airlock log` and `airlock://audit` read across them (#36).
- How to report a vulnerability and what counts as one, how to contribute, and issue forms (#34).

### Fixed

- `airlock configure`, run before anything else, created Airlock's directory open to others; it is now open to its
  owner only, as the rest of Airlock makes it (#35).
- A client file Airlock cannot read is reported as one it cannot edit, instead of stopping `airlock connect` with an
  error (#35).
- Airlock's directory is written without a double slash when `HOME` is `/` (#35).

### Security

- Commands shown by `airlock grants` and `airlock log` cannot write terminal escape sequences or console tags (#33).
- A `models.yaml` that is not valid YAML no longer copies its lines, the key's among them, into the error that
  `airlock doctor`, `airlock configure` and the server's log show (#35).

## 0.2.6 (2026-11-04)

### Fixed

- `airlock connect goose`, on a Goose that had not started yet, left its developer extension's shell tool on: the
  agent could run commands around Airlock. It now writes that extension without it (#31).

### Changed

- The README says how each client was tested and what to expect in it (#32).

## 0.2.5 (2026-11-03)

### Fixed

- `airlock connect --remove` also removes the directories connecting created, once empty (#29).

### Security

- The grants file is written readable by its owner only, as a new file each time (#29).
- Release builds check static-php-cli's archive against a pinned SHA-256 (#29).

## 0.2.4 (2026-11-03)

### Fixed

- Release builds fetch musl from Debian's mirror, pinned by hash: musl.libc.org was unreachable (#28).

## 0.2.3 (2026-11-03, not published)

Its release build failed; 0.2.4 holds the same changes.

### Added

- `airlock --version`, and the server reports its name and version to the client (#24, #26).

### Fixed

- `airlock connect --remove` deletes the files connecting created (#23).
- Reading the audit log's last entries reads it from its end, instead of loading it whole (#27).
- The README clones the repository for the demo when Airlock was installed as a binary (#22).

### Security

- The audit log and its directory are readable by their owner only, and a log an earlier version left open is
  replaced (#25).

## 0.2.2 (2026-11-01)

### Fixed

- `airlock connect` keeps each file as it was before Airlock first changed it, and writes nothing over a file that
  changed while it asked (#19).

## 0.2.1 (2026-11-01)

### Added

- `airlock connect` for Claude Code, Codex, Cursor, VS Code and Goose (#18).

## 0.2.0 (2026-11-01)

### Added

- `airlock configure` finds and calibrates the models, with the key typed in a terminal, never through the agent
  (#17).
- The logo, and documentation for any MCP client (#12, #13, #14).

### Security

- The committed development `APP_SECRET` is removed (#15).

## 0.1.0 (2026-10-31)

The first release: the shell parser, the policy, bubblewrap and `sandbox-exec` sandboxes, the `run_command` tool,
the form with once, this session, always or refuse, signed state, grants, the audit log, the model checks of
outputs and commands through any OpenAI-compatible API, and standalone binaries for Linux and macOS, with an
installer (#1 to #11).
