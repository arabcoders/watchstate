#!/usr/bin/env python3

import argparse
import json
import re
import sys

try:
    from datetime import UTC, datetime
except ImportError:
    from datetime import datetime, timezone

    UTC = timezone.utc

try:
    import git
except ImportError:
    print("Please install GitPython: pip install GitPython", file=sys.stderr)
    sys.exit(1)


def get_sorted_tags(repo):
    """Returns all tags sorted by their commit date (newest first)."""
    return sorted(repo.tags, key=lambda t: t.commit.committed_datetime, reverse=True)


def get_commits_between(repo, start, end):
    """Return commits between two refs (no merges)."""
    return list(repo.iter_commits(f"{start}..{end}", no_merges=True))


CO_AUTHORED_BY_RE = re.compile(r"^Co-authored-by:\s.*$", re.IGNORECASE)


def sanitize_commit_message(message):
    """Remove co-author trailers from commit messages before publishing."""
    lines = [line for line in message.strip().splitlines() if not CO_AUTHORED_BY_RE.match(line)]
    return "\n".join(lines).strip()


def generate_changelog(repo_path, changelog_path):
    repo = git.Repo(repo_path)
    tags = get_sorted_tags(repo)
    changelog = []

    head_commit = repo.head.commit
    head_branch = None
    try:
        head_branch = repo.active_branch.name
    except TypeError:
        pass  # Detached HEAD

    # 🔼 If HEAD is not on 'dev', include unmerged commits from dev
    if "dev" in repo.heads and head_branch != "dev":
        try:
            dev_branch = repo.heads["dev"]
            main_branch_name = "main" if "main" in repo.heads else "master"
            main_branch = repo.heads[main_branch_name]

            unmerged_commits = list(
                repo.iter_commits(
                    f"{main_branch.name}..{dev_branch.name}", no_merges=True
                )
            )
            if unmerged_commits:
                changelog.insert(
                    0,
                    {
                        "tag": f"Unmerged ({dev_branch.name} branch)",
                        "full_sha": dev_branch.commit.hexsha,
                        "date": dev_branch.commit.committed_datetime.astimezone(
                            UTC
                        ).isoformat(),
                        "commits": [
                            {
                                "sha": c.hexsha[:8],
                                "full_sha": c.hexsha,
                                "message": sanitize_commit_message(c.message),
                                "author": c.author.name,
                                "date": c.committed_datetime.astimezone(
                                    UTC
                                ).isoformat(),
                            }
                            for c in unmerged_commits
                        ],
                    },
                )
        except Exception as e:
            print(f"[WARN] Could not get unmerged dev commits: {e}", file=sys.stderr)

    # Regular tag-based changelog
    if not tags:
        start = repo.commit(repo.git.rev_list("--max-parents=0", "HEAD"))
        commits = get_commits_between(repo, start.hexsha, "HEAD")
        changelog.append(
            {
                "tag": "Initial Release",
                "date": datetime.now(UTC).isoformat(),
                "commits": [
                    {
                        "sha": c.hexsha[:8],
                        "full_sha": c.hexsha,
                        "message": sanitize_commit_message(c.message),
                        "author": c.author.name,
                        "date": c.committed_datetime.astimezone(UTC).isoformat(),
                    }
                    for c in commits
                ],
            }
        )
    else:
        for i in range(len(tags) - 1):
            newer, older = tags[i], tags[i + 1]
            commits = get_commits_between(
                repo, older.commit.hexsha, newer.commit.hexsha
            )
            if not commits:
                continue
            changelog.append(
                {
                    "tag": newer.name,
                    "full_sha": newer.commit.hexsha,
                    "date": newer.commit.committed_datetime.astimezone(UTC).isoformat(),
                    "commits": [
                        {
                            "sha": c.hexsha[:8],
                            "full_sha": c.hexsha,
                            "message": sanitize_commit_message(c.message),
                            "author": c.author.name,
                            "date": c.committed_datetime.astimezone(UTC).isoformat(),
                        }
                        for c in commits
                    ],
                }
            )

        # Unreleased changes after latest tag
        if head_commit.hexsha != tags[0].commit.hexsha:
            commits = get_commits_between(
                repo, tags[0].commit.hexsha, head_commit.hexsha
            )
            if commits:
                unreleased_label = (
                    f"Unreleased ({head_branch})"
                    if head_branch
                    else f"Unreleased ({head_commit.hexsha[:8]})"
                )
                changelog.insert(
                    0,
                    {
                        "tag": unreleased_label,
                        "full_sha": head_commit.hexsha,
                        "date": head_commit.committed_datetime.astimezone(
                            UTC
                        ).isoformat(),
                        "commits": [
                            {
                                "sha": c.hexsha[:8],
                                "full_sha": c.hexsha,
                                "message": sanitize_commit_message(c.message),
                                "author": c.author.name,
                                "date": c.committed_datetime.astimezone(
                                    UTC
                                ).isoformat(),
                            }
                            for c in commits
                        ],
                    },
                )

    with open(changelog_path, "w", encoding="utf-8") as f:
        json.dump(changelog, f, indent=2)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(
        description="Generate CHANGELOG.json from Git tags."
    )
    parser.add_argument("--repo_path", "-p", default=".", help="Path to git repo")
    parser.add_argument(
        "--changelog_path", "-f", default="CHANGELOG.json", help="Output file path"
    )
    args = parser.parse_args()

    generate_changelog(args.repo_path, args.changelog_path)
