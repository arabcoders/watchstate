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


def get_preferred_ref(repo, *names):
    """Return the first matching ref by name."""
    refs_by_name = {ref.name: ref for ref in repo.refs}
    for name in names:
        ref = refs_by_name.get(name)
        if ref is not None:
            return ref
    return None


def add_commits_by_sha(target, commits):
    """Store commits by SHA so overlapping refs only appear once."""
    for commit in commits:
        target[commit.hexsha] = commit


def build_untagged_entry(commits_by_sha):
    """Create one entry for commits that are not part of a tag yet."""
    if not commits_by_sha:
        return None

    commits = sorted(
        commits_by_sha.values(), key=lambda c: c.committed_datetime, reverse=True
    )
    latest_commit = commits[0]
    return {
        "tag": "Untagged",
        "full_sha": latest_commit.hexsha,
        "date": latest_commit.committed_datetime.astimezone(UTC).isoformat(),
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


def generate_changelog(repo_path, changelog_path):
    repo = git.Repo(repo_path)
    tags = get_sorted_tags(repo)
    changelog = []

    head_commit = repo.head.commit
    head_branch = None
    untagged_commits = {}
    try:
        head_branch = repo.active_branch.name
    except TypeError:
        pass  # Detached HEAD

    # Collect dev commits not yet in main so they share one untagged bucket.
    if head_branch != "dev":
        try:
            dev_branch = get_preferred_ref(repo, "origin/dev", "dev")
            main_branch = get_preferred_ref(
                repo, "origin/main", "origin/master", "main", "master"
            )

            if dev_branch is not None and main_branch is not None:
                unmerged_commits = list(
                    repo.iter_commits(
                        f"{main_branch.name}..{dev_branch.name}", no_merges=True
                    )
                )
                add_commits_by_sha(untagged_commits, unmerged_commits)
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

        # Collect commits after the latest tag into the same untagged bucket.
        if head_commit.hexsha != tags[0].commit.hexsha:
            commits = get_commits_between(
                repo, tags[0].commit.hexsha, head_commit.hexsha
            )
            if commits:
                add_commits_by_sha(untagged_commits, commits)

        untagged_entry = build_untagged_entry(untagged_commits)
        if untagged_entry:
            changelog.insert(0, untagged_entry)

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
