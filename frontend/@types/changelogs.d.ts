type changelogs = changeset[]

type changeset = {
    tag: string
    full_sha: string
    date: string
    commits: Commit[]
}

type Commit = {
    sha: string
    full_sha: string
    message: string
    author: string
    date: string
}

export type {changelogs, changeset, Commit}
