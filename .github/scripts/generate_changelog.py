#!/usr/bin/env python3

import argparse
import json
import sys
from datetime import UTC, datetime

try:
    import git  # type: ignore
except ImportError:
    print("Please install GitPython: pip install GitPython", file=sys.stderr)  # noqa: T201
    sys.exit(1)


def get_sorted_tags(repo):
    """Returns all tags sorted by their commit date (newest first)."""
    return sorted(repo.tags, key=lambda t: t.commit.committed_datetime, reverse=True)


def get_commits_between(repo, start, end):
    """Return commits between two refs (no merges)."""
    return list(repo.iter_commits(f"{start}..{end}", no_merges=True))


def generate_changelog(repo_path, changelog_path):
    repo = git.Repo(repo_path)
    tags = get_sorted_tags(repo)
    changelog = []

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
                        "message": c.message.strip(),
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
            commits = get_commits_between(repo, older.commit.hexsha, newer.commit.hexsha)
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
                            "message": c.message.strip(),
                            "author": c.author.name,
                            "date": c.committed_datetime.astimezone(UTC).isoformat(),
                        }
                        for c in commits
                    ],
                }
            )

        # Add HEAD -> latest tag if ahead
        head = repo.head.commit
        if head.hexsha != tags[0].commit.hexsha:
            commits = get_commits_between(repo, tags[0].commit.hexsha, head.hexsha)
            if commits:
                changelog.insert(
                    0,
                    {
                        "tag": f"Unreleased ({head.hexsha[:8]})",
                        "full_sha": head.hexsha,
                        "date": head.committed_datetime.astimezone(UTC).isoformat(),
                        "commits": [
                            {
                                "sha": c.hexsha[:8],
                                "full_sha": c.hexsha,
                                "message": c.message.strip(),
                                "author": c.author.name,
                                "date": c.committed_datetime.astimezone(UTC).isoformat(),
                            }
                            for c in commits
                        ],
                    },
                )

    with open(changelog_path, "w", encoding="utf-8") as f:
        json.dump(changelog, f, indent=2)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Generate CHANGELOG.json from Git tags.")
    parser.add_argument("--repo_path", "-p", default=".", help="Path to git repo")
    parser.add_argument("--changelog_path", "-f", default="CHANGELOG.json", help="Output file path")
    args = parser.parse_args()

    generate_changelog(args.repo_path, args.changelog_path)
