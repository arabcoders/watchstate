export interface JsonCommand {
    op: 'set' | 'delete'
    path: Array<string>
    rawValue?: string
}

export interface JsonCommandParseSuccess {
    ok: true
    command: JsonCommand
}

export interface JsonCommandParseError {
    ok: false
    error: string
}

export type ParseCommandResult = JsonCommandParseSuccess | JsonCommandParseError

export interface JsonCommandApplySuccess {
    ok: true
    obj: Record<string, any>
}

export interface JsonCommandApplyError {
    ok: false
    error: string
}

export type ApplyCommandResult = JsonCommandApplySuccess | JsonCommandApplyError

const splitPathSegments = (pathText: string): Array<string> => {
    const segments: Array<string> = []
    let current = ''
    let escaping = false

    for (let i = 0; i < pathText.length; i++) {
        const ch = pathText[i]
        if (escaping) {
            current += ch
            escaping = false
            continue
        }

        if (ch === '\\') {
            escaping = true
            continue
        }

        if (ch === '.') {
            segments.push(current)
            current = ''
            continue
        }

        current += ch
    }
    segments.push(current)
    return segments.map(seg => seg.replace(/\\\\/g, '\\').replace(/\\\./g, '.'))
}

const parseCommand = (input: string): ParseCommandResult => {
    if (!input || 0 === input.trim().length) {
        return {ok: false, error: 'Empty command'}
    }

    const firstSlash = input.indexOf('/')
    if (-1 === firstSlash) {
        return {ok: false, error: 'Invalid command format: missing "/" separators'}
    }

    const opChar = input.slice(0, firstSlash).trim()
    const rest = input.slice(firstSlash + 1)

    const secondSlash = rest.indexOf('/')
    if (-1 === secondSlash) {
        return {ok: false, error: 'Invalid command format: missing second "/" separator'}
    }

    const pathText = rest.slice(0, secondSlash).trim()
    const valueText = rest.slice(secondSlash + 1)

    if (!pathText || 0 === pathText.length) {
        return {ok: false, error: 'Path cannot be empty'}
    }

    let op: 'set' | 'delete'
    if ('s' === opChar) {
        op = 'set'
    } else if ('d' === opChar) {
        op = 'delete'
    } else {
        return {ok: false, error: `Unknown operation '${opChar}' - supported: s (set), d (delete)`}
    }

    const path = splitPathSegments(pathText).filter(p => p.length > 0)

    if (0 === path.length) {
        return {ok: false, error: 'Invalid path'}
    }

    const command: JsonCommand = {op, path}

    if ('set' === op) {
        command.rawValue = valueText
    }

    return {ok: true, command}
}

const parseValue = (raw: string | undefined): any => {
    if (raw === undefined) {
        return undefined
    }

    const trimmed = raw.trim()

    if (0 === trimmed.length) {
        return ''
    }

    try {
        return JSON.parse(trimmed)
    } catch (e) {
        return raw
    }
}

const applyCommand = (obj: Record<string, any>, command: JsonCommand): ApplyCommandResult => {
    const out: Record<string, any> = {...obj}

    if (!command.path || 0 === command.path.length) {
        return {ok: false, error: 'Empty path in command'}
    }

    let at: Record<string, any> = out
    const path = command.path

    for (let i = 0; i < path.length - 1; i++) {
        const segment = String(path[i])
        const current = (at as Record<string, any>)[segment]
        if ('undefined' === typeof current || null === current) {
            (at as Record<string, any>)[segment] = {}
        } else if ('object' !== typeof current) {
            return {ok: false, error: `Path conflict: '${segment}' is not an object`}
        }
        at = (at as Record<string, any>)[segment] as Record<string, any>
    }

    const last = String(path[path.length - 1])

    if ('undefined' === typeof last) {
        return {ok: false, error: 'Invalid last path segment'}
    }

    if ('set' === command.op) {
        (at as Record<string, any>)[last] = parseValue(command.rawValue)
        return {ok: true, obj: out}
    }

    if ('delete' === command.op) {
        if (Object.prototype.hasOwnProperty.call(at, last)) {
            delete (at as Record<string, any>)[last]
            return {ok: true, obj: out}
        }
        return {ok: false, error: `Key not found: ${command.path.join('.')}`}
    }

    return {ok: false, error: 'Unsupported operation'}
}

export {parseCommand, parseValue, applyCommand}
