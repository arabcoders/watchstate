#!/usr/bin/env python3

import git
import argparse
import json
from datetime import datetime, timezone


def get_tags(repo, branch_name):
    """Returns sorted tags by date, filtered by branch name."""
    tags = [tag for tag in repo.tags if tag.name.startswith(branch_name)]
    # Sort tags in reverse chronological order based on the tag commit's date.
    tags = sorted(tags, key=lambda t: t.commit.committed_datetime, reverse=True)
    return tags


def get_commits_between(repo, start_commit, end_commit):
    """Get commit objects between two commits (excluding merges)."""
    commits = list(repo.iter_commits(f"{start_commit}..{end_commit}", no_merges=True))
    return commits


def format_tag(tag, branch_name):
    """Formats the tag as 'branch-YYYYMMDD-shortsha'."""
    commit_date = datetime.fromtimestamp(
        tag.commit.committed_date, timezone.utc
    ).strftime("%Y%m%d")
    commit_hash = tag.commit.hexsha[:7]  # Short commit hash
    return f"{branch_name}-{commit_date}-{commit_hash}"


def generate_changelog(repo_path, changelog_path, branch_name):
    repo = git.Repo(repo_path)
    tags = get_tags(repo, branch_name)
    changelog_data = []

    # No tags: output an "Initial Release" entry covering the entire history.
    if not tags:
        start_commit = repo.commit(repo.git.rev_list("--max-parents=0", "HEAD"))
        commits = get_commits_between(repo, start_commit.hexsha, "HEAD")
        date_str = datetime.now(timezone.utc).strftime("%Y-%m-%d")
        release_entry = {
            "tag": "Initial Release",
            "date": date_str,
            "commits": []
        }
        for commit in commits:
            commit_entry = {
                "sha": commit.hexsha[:7],
                "message": commit.message.strip(),
                "author": commit.author.name,
                "date": commit.committed_datetime.strftime("%Y-%m-%d")
            }
            release_entry["commits"].append(commit_entry)
        changelog_data.append(release_entry)

    else:
        # Process each pair of tags (newest tag down to the second oldest tag).
        # This will output a changelog entry for each tag that has commits
        # between it and the next tag.
        for i in range(len(tags) - 1):
            tag = tags[i]         # Newest tag in the pair.
            next_tag = tags[i + 1]  # Older tag

            # Retrieve commits between the older and newer tag.
            commits = get_commits_between(repo, next_tag.commit.hexsha, tag.commit.hexsha)

            if not commits:
                continue

            # Use the commit date of the tag for the release entry.
            date_str = tag.commit.committed_datetime.strftime("%Y-%m-%d")
            formatted_tag = format_tag(tag, branch_name)
            release_entry = {
                "tag": formatted_tag,
                "date": date_str,
                "commits": []
            }
            for commit in commits:
                commit_entry = {
                    "sha": commit.hexsha[:7],
                    "message": commit.message.strip(),
                    "author": commit.author.name,
                    "date": commit.committed_datetime.strftime("%Y-%m-%d")
                }
                release_entry["commits"].append(commit_entry)
            changelog_data.append(release_entry)

    # Write the changelog data as a JSON file.
    with open(changelog_path, "w", encoding="utf-8") as f:
        json.dump(changelog_data, f, indent=4)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(
        description="Generate a changelog from git history and output JSON."
    )
    parser.add_argument(
        "--repo_path", "-p", type=str, help="Path to the git repository", default="."
    )
    parser.add_argument(
        "--changelog_path",
        "-f",
        type=str,
        default="./CHANGELOG.json",
        help="Path to the output JSON file (default: ./CHANGELOG.json)",
    )
    parser.add_argument(
        "--branch_name",
        "-b",
        type=str,
        default="master",
        help="Branch name to filter tags (default: master)",
    )
    args = parser.parse_args()

    generate_changelog(args.repo_path, args.changelog_path, args.branch_name)
