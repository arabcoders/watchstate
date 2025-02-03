#!/usr/bin/env python3

import git
import argparse
from datetime import datetime, timezone

def get_tags(repo, branch_name):
    """Returns sorted tags by date, filtered by branch name."""
    tags = [tag for tag in repo.tags if tag.name.startswith(branch_name)]
    tags = sorted(tags, key=lambda t: t.commit.committed_datetime, reverse=True)
    return tags

def get_commits_between(repo, start_commit, end_commit):
    """Get commit messages between two commits (excluding merges)."""
    commits = list(repo.iter_commits(f"{start_commit}..{end_commit}", no_merges=True))
    return commits

def format_tag(tag, branch_name):
    """Formats the tag as 'branch-latest-tag-date-hash'."""
    commit_date = datetime.fromtimestamp(tag.commit.committed_date, timezone.utc).strftime("%Y%m%d")
    commit_hash = tag.commit.hexsha[:7]  # Short commit hash
    return f"{branch_name}-{commit_date}-{commit_hash}"

def generate_changelog(repo_path, changelog_path, branch_name):
    repo = git.Repo(repo_path)
    tags = get_tags(repo, branch_name)
    changelog_entries = []

    if not tags:
        start_commit = repo.commit(repo.git.rev_list("--max-parents=0", "HEAD"))
        commits = get_commits_between(repo, start_commit.hexsha, "HEAD")
        date_str = datetime.now(timezone.utc).strftime("%Y-%m-%d")
        changelog_entries.append(f"# {date_str} - Initial Release\n\n")

        for commit in commits:
            changelog_entries.append(f"* {commit.summary}\n")
    else:
        for i in range(len(tags) - 1):
            tag = tags[i]  # Newest tag
            next_tag = tags[i + 1]  # Older tag

            tag_commit = tag.commit.hexsha
            next_tag_commit = next_tag.commit.hexsha

            commits = get_commits_between(repo, next_tag_commit, tag_commit)

            if not commits:
                continue

            date_str = datetime.now(timezone.utc).strftime("%Y-%m-%d")
            formatted_tag = format_tag(tag, branch_name)
            changelog_entries.append(f"# {date_str} - {formatted_tag}\n\n")

            for commit in commits:
                changelog_entries.append(f"* {commit.summary}\n")

            changelog_entries.append("\n")

    with open(changelog_path, "w", encoding="utf-8") as f:
        f.writelines(changelog_entries)

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Generate a changelog from git history.")
    parser.add_argument("--repo_path", "-p", type=str, help="Path to the git repository", default=".")
    parser.add_argument("--changelog_path", "-f", type=str, default="./CHANGELOG.md", help="Path to the output CHANGELOG.md file (default: ./CHANGELOG.md)")
    parser.add_argument("--branch_name", "-b", type=str, default="master", help="Branch name to filter tags (default: master)")
    args = parser.parse_args()

    generate_changelog(args.repo_path, args.changelog_path, args.branch_name)
