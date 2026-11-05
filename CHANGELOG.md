# Changelog

What changed in each release, newest first. Versions follow [semantic versioning](https://semver.org): until 1.0, a
minor version may change the configuration and file formats.

## Unreleased

### Added

- `github_pr_checks` and `github_pr_comments`: the agent reads a pull request's CI checks, and its reviews and
  comments, line comments included. Airlock runs the user's GitHub CLI itself, outside the sandbox and without asking,
  and gives back what it read as a command's output: secrets masked, checked for prompt injection, marked as data,
  in the audit log as `read_from_github`.

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
