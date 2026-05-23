import type { JsonObject, JsonValue, LogEntry } from '~/types';

export type ParsedLogEntry = LogEntry;

export type LogTone = 'neutral' | 'info' | 'warning' | 'error' | 'success';
export type LogLevel = 'debug' | 'info' | 'notice' | 'warning' | 'error';
export type LogLevelColor = 'neutral' | 'info' | 'primary' | 'warning' | 'error';

export type LogDetailRow = {
  label: string;
  value: string;
  icon: string;
};

export interface RawLogResponse {
  filename: string;
  offset: number;
  next: number | null;
  max: number;
  type: 'log' | 'json';
  lines: Array<string>;
}

export interface RawRecentLogFile {
  filename: string;
  type: string;
  date: string;
  size: number;
  modified: string;
  lines: Array<string>;
}

const LEVEL_REGEX =
  /^(?:\[[^\]]+\]\s*)?(?:[a-z0-9_.-]+\.)?(?<level>EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG):\s*/i;
const DATE_REGEX =
  /^\[([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?[+-][0-9]{2}:[0-9]{2})]/i;
const EVENT_REGEX =
  /\[event:(?<event_id>[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})]\s*/i;
const IDENT_REGEX = /'((?<client>\w+):\s)?(?<user>\w+)@(?<backend>[\w-]+)'/i;
let logSequence = 0;

const LOG_LEVEL_ICON: Record<LogLevel, string> = {
  debug: 'i-lucide-terminal',
  info: 'i-lucide-info',
  notice: 'i-lucide-bell-ring',
  warning: 'i-lucide-triangle-alert',
  error: 'i-lucide-circle-x',
};

const LOG_LEVEL_COLOR: Record<LogLevel, LogLevelColor> = {
  debug: 'neutral',
  info: 'info',
  notice: 'primary',
  warning: 'warning',
  error: 'error',
};

const hashText = (value: string): string => {
  let hash = 0;

  for (let index = 0; index < value.length; index++) {
    hash = ((hash << 5) - hash + value.charCodeAt(index)) | 0;
  }

  return Math.abs(hash).toString(36);
};

const nextLogId = (prefix: string, seed: string): string => {
  logSequence += 1;
  return `${prefix}-${hashText(seed)}-${logSequence}`;
};

const isObject = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && false === Array.isArray(value);
};

const asRecord = (value: unknown): Record<string, unknown> | null => {
  return isObject(value) ? value : null;
};

const asString = (value: unknown): string | null => {
  if (typeof value === 'string') {
    const normalized = value.trim();
    return '' === normalized ? null : normalized;
  }

  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value);
  }

  return null;
};

const flattenInto = (target: JsonObject, source: Record<string, unknown>, prefix = ''): void => {
  for (const [key, value] of Object.entries(source)) {
    const path = '' === prefix ? key : `${prefix}.${key}`;

    if (isObject(value)) {
      flattenInto(target, value, path);
      continue;
    }

    if (Array.isArray(value)) {
      try {
        target[path] = JSON.stringify(value) as JsonValue;
      } catch {
        target[path] = String(value);
      }
      continue;
    }

    if (
      typeof value === 'string' ||
      typeof value === 'number' ||
      typeof value === 'boolean' ||
      value === null
    ) {
      target[path] = value;
      continue;
    }

    target[path] = String(value);
  }
};

const emptyEntry = (raw: string): ParsedLogEntry => ({
  id: nextLogId('log', raw),
  state_id: null,
  remote_id: null,
  event_id: null,
  user: null,
  backend: null,
  date: null,
  datetime: null,
  level: null,
  logger: null,
  text: raw,
  message: null,
  fields: {},
  raw,
});

const parseJsonl = (line: string): ParsedLogEntry | null => {
  let payload: unknown = null;

  try {
    payload = JSON.parse(line) as unknown;
  } catch {
    return null;
  }

  if (!isObject(payload)) {
    return null;
  }

  const id = asString(payload.id);
  const datetime = asString(payload.datetime);
  const level = asString(payload.level)?.toLowerCase() ?? null;
  const logger = asString(payload.logger) ?? asString(payload.channel);
  const message = typeof payload.message === 'string' ? payload.message : null;

  if (!id || !datetime || !level || !logger || null === message) {
    return null;
  }

  const fieldsSource = asRecord(payload.fields) ?? {};
  const fields: JsonObject = {};
  flattenInto(fields, fieldsSource);

  const stateId = asString(
    fields.state_id ?? fields['item.state_id'] ?? fields['attributes.item.state_id'],
  );
  const remoteId = asString(
    fields.remote_id ?? fields['item.remote_id'] ?? fields['attributes.item.remote_id'],
  );
  const eventId = asString(fields.event_id ?? fields['event.id'] ?? fields['attributes.event.id']);
  const user = asString(fields.user ?? fields['user.name'] ?? fields['attributes.user.name']);
  const backend = asString(
    fields.backend ?? fields['backend.name'] ?? fields['attributes.backend.name'] ?? fields.via,
  );

  return {
    id,
    state_id: stateId,
    remote_id: remoteId,
    event_id: eventId,
    user,
    backend,
    date: datetime,
    datetime,
    level,
    logger,
    text: message,
    message,
    fields,
    source: (asRecord(payload.source) ?? undefined) as JsonObject | undefined,
    process: (asRecord(payload.process) ?? undefined) as JsonObject | undefined,
    exception: typeof payload.exception === 'string' ? payload.exception : null,
    exception_message:
      typeof payload.exception_message === 'string' ? payload.exception_message : null,
    stack: typeof payload.stack === 'string' ? payload.stack : null,
    raw: line,
  };
};

const parseLegacy = (line: string): ParsedLogEntry => {
  const dateMatch = DATE_REGEX.exec(line);
  const eventMatch = EVENT_REGEX.exec(line);
  const levelMatch = LEVEL_REGEX.exec(line);
  const identMatch = IDENT_REGEX.exec(line);

  let text = dateMatch ? line.replace(DATE_REGEX, '').trim() : line;

  if (eventMatch) {
    text = text.replace(EVENT_REGEX, '').trim();
  }

  return {
    id: nextLogId('legacy', line),
    state_id: null,
    remote_id: null,
    event_id: eventMatch?.groups?.event_id ?? null,
    user: identMatch?.groups?.user ?? null,
    backend: identMatch?.groups?.backend ?? null,
    date: dateMatch?.[1] ?? null,
    datetime: dateMatch?.[1] ?? null,
    level: levelMatch?.groups?.level?.toLowerCase() ?? null,
    logger: null,
    text,
    message: null,
    fields: {},
    raw: line,
  };
};

const parseLogLine = (line: string): ParsedLogEntry => {
  const normalized = line.trim();

  if (!normalized) {
    return emptyEntry('');
  }

  return parseJsonl(normalized) ?? parseLegacy(normalized);
};

const parseLogLines = (lines: Array<string>): Array<ParsedLogEntry> => {
  return lines.map((line) => parseLogLine(line)).filter((entry) => '' !== entry.raw.trim());
};

const logMessageText = (entry: ParsedLogEntry): string => {
  if (entry.message && entry.exception_message) {
    return `${entry.message} [${entry.exception_message}]`;
  }

  return entry.message ?? entry.text;
};

const logHostLabel = (entry: ParsedLogEntry): string => {
  const fields = entry.fields ?? {};
  const candidates = [
    fields['structured.request.name'],
    fields.hostname,
    fields['route.ip'],
    fields.task_id,
    fields.command,
    fields.user,
    fields.backend,
    fields['cli.stream'],
    entry.source?.module,
    entry.process?.name,
    entry.logger,
  ];

  for (const candidate of candidates) {
    const value = asString(candidate);
    if (value) {
      return value;
    }
  }

  return '-';
};

const logSeverityTone = (
  entry: ParsedLogEntry,
): 'error' | 'warning' | 'success' | 'info' | 'neutral' => {
  const value = `${entry.level ?? ''} ${logMessageText(entry)}`.toLowerCase();

  if (/(critical|crit|alert|error|err|fatal|panic|exception|failed)/.test(value)) {
    return 'error';
  }

  if (/(warning|warn|notice|noti|deprecated)/.test(value)) {
    return 'warning';
  }

  if (/(success|started|listening|connected|ready|complete|done)/.test(value)) {
    return 'success';
  }

  if (/(info|inf|debug|deb|trace)/.test(value)) {
    return 'info';
  }

  return 'neutral';
};

const getLogLevel = (level: string | null | undefined): LogLevel => {
  switch ((level ?? '').toLowerCase()) {
    case 'info':
      return 'info';
    case 'notice':
      return 'notice';
    case 'warning':
    case 'warn':
      return 'warning';
    case 'error':
    case 'critical':
    case 'fatal':
    case 'alert':
    case 'emergency':
      return 'error';
    default:
      return 'debug';
  }
};

const logTimestampLabel = (value: string | null | undefined): string => {
  if (!value) {
    return '--:--:--';
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return date.toLocaleTimeString([], {
    hour12: false,
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  });
};

const logTimestampTitle = (value: string | null | undefined): string => {
  if (!value) {
    return 'No timestamp';
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return date.toLocaleString();
};

const logTimestampClipboard = (value: string | null | undefined): string => {
  const normalized = value?.trim() ?? '';
  return '' === normalized ? '--:--:--' : normalized;
};

const logDisplayLine = (entry: ParsedLogEntry): string => {
  const host = logHostLabel(entry);
  const logger = entry.logger === host ? null : entry.logger;
  const parts = [
    logTimestampLabel(entry.datetime ?? entry.date),
    '-' === host ? null : host,
    (entry.level ?? 'info').toUpperCase(),
    logger,
    logMessageText(entry),
  ];

  return parts.filter((value) => value && '' !== value.trim()).join(' ');
};

const logClipboardLine = (entry: ParsedLogEntry): string => {
  const parts = [`[${logTimestampClipboard(entry.datetime ?? entry.date)}]`];

  if (entry.logger) {
    parts.push(`${entry.logger}.${getLogLevel(entry.level).toUpperCase()}:`);
  } else {
    parts.push(getLogLevel(entry.level).toUpperCase() + ':');
  }

  parts.push(logMessageText(entry));

  if (entry.exception_message) {
    parts.push(`: ${entry.exception_message}`);
  }

  return parts.join(' ');
};

const logClipboardText = (entries: Array<ParsedLogEntry>): string => {
  return entries.map((entry) => logClipboardLine(entry)).join('\n');
};

const logSearchText = (entry: ParsedLogEntry): string => {
  const values = [
    entry.raw,
    entry.text,
    entry.message,
    entry.level,
    entry.logger,
    entry.exception,
    entry.exception_message,
    entry.stack,
  ];

  try {
    values.push(JSON.stringify(entry.fields));
  } catch {
    // -- ignore invalid structured payloads when building a search string.
  }

  return values.filter(Boolean).join(' ').toLowerCase();
};

const formatDetailValue = (value: unknown): string => {
  if (value === undefined || value === null || value === '') {
    return '';
  }

  if (typeof value === 'string') {
    return value;
  }

  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value);
  }

  return JSON.stringify(value);
};

const compactRows = (
  rows: Array<{ label: string; value: unknown; icon: string }>,
): Array<LogDetailRow> => {
  return rows
    .map((row) => ({
      label: row.label,
      value: formatDetailValue(row.value),
      icon: row.icon,
    }))
    .filter((row) => Boolean(row.value));
};

const formatNameId = (nameValue: unknown, idValue: unknown): string => {
  const left = formatDetailValue(nameValue);
  const right = formatDetailValue(idValue);

  if (left && right) {
    return `${left} / ${right}`;
  }

  return left || right;
};

const logDetailRows = (log: ParsedLogEntry): Array<LogDetailRow> => {
  return compactRows([
    { label: 'File', value: log.source?.file, icon: 'i-lucide-file' },
    { label: 'Line', value: log.source?.line, icon: 'i-lucide-hash' },
    { label: 'Function', value: log.source?.function, icon: 'i-lucide-code-2' },
    { label: 'Module', value: log.source?.module, icon: 'i-lucide-box' },
    { label: 'Path', value: log.source?.path, icon: 'i-lucide-folder-tree' },
    {
      label: 'Process / ID',
      value: formatNameId(log.process?.name, log.process?.id),
      icon: 'i-lucide-cpu',
    },
  ]);
};

const logFieldRows = (log: ParsedLogEntry): Array<LogDetailRow> => {
  return compactRows(
    Object.entries(log.fields ?? {}).map(([label, value]) => ({
      label,
      value,
      icon: 'i-lucide-tag',
    })),
  );
};

const formatTraceLines = (trace: unknown): string => {
  if (!Array.isArray(trace)) {
    return '';
  }

  const lines = trace
    .map((frame, index) => {
      if (!frame || typeof frame !== 'object' || Array.isArray(frame)) {
        return '';
      }

      const record = frame as Record<string, unknown>;
      const file = typeof record.file === 'string' ? record.file.trim() : '';
      const line =
        typeof record.line === 'number'
          ? record.line
          : typeof record.line === 'string' && record.line.trim()
            ? Number(record.line)
            : null;
      const fn = typeof record.function === 'string' ? record.function.trim() : '';
      const klass = typeof record.class === 'string' ? record.class.trim() : '';
      const type = typeof record.type === 'string' ? record.type.trim() : '';

      const call = fn ? `${klass}${type}${fn}` : '';
      const location = file
        ? `${file}${line !== null && !Number.isNaN(line) ? `:${line}` : ''}`
        : '';

      if (call && location) {
        return `#${index} ${call} at ${location}`;
      }

      if (call) {
        return `#${index} ${call}`;
      }

      if (location) {
        return `#${index} ${location}`;
      }

      return '';
    })
    .filter((line) => Boolean(line));

  return lines.join('\n');
};

const formatLogException = (exception: string | null | undefined): string => {
  if (!exception) {
    return '';
  }

  const trimmed = exception.trim();
  const jsonStart = trimmed.indexOf('\n[');

  if (-1 === jsonStart) {
    return trimmed;
  }

  const headline = trimmed.slice(0, jsonStart).trim();
  const trace = trimmed.slice(jsonStart + 1).trim();

  try {
    const renderedTrace = formatTraceLines(JSON.parse(trace) as unknown);

    if (!renderedTrace) {
      return trimmed;
    }

    return `${headline}\n${renderedTrace}`;
  } catch {
    return trimmed;
  }
};

const formatLogStack = (stack: string | null | undefined): string => {
  if (!stack) {
    return '';
  }

  try {
    return JSON.stringify(JSON.parse(stack) as unknown, null, 2);
  } catch {
    return stack;
  }
};

const logRaw = (log: ParsedLogEntry): string => {
  try {
    return JSON.stringify(JSON.parse(log.raw) as unknown, null, 2);
  } catch {
    return log.raw;
  }
};

const logLevelBadgeClass = (level: LogLevel): Array<string> => [
  'inline-flex w-24 cursor-pointer items-center gap-1.5 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide whitespace-nowrap',
  'debug' === level ? 'bg-muted/40 text-muted' : '',
  'info' === level ? 'bg-info/10 text-info' : '',
  'notice' === level ? 'bg-primary/12 text-primary' : '',
  'warning' === level ? 'bg-warning/10 text-warning' : '',
  'error' === level ? 'bg-error/10 text-error' : '',
];

export {
  emptyEntry,
  formatLogException,
  formatLogStack,
  getLogLevel,
  LOG_LEVEL_COLOR,
  LOG_LEVEL_ICON,
  logDetailRows,
  logClipboardLine,
  logClipboardText,
  logDisplayLine,
  logFieldRows,
  logHostLabel,
  logLevelBadgeClass,
  logMessageText,
  logRaw,
  logSearchText,
  logSeverityTone,
  logTimestampLabel,
  logTimestampTitle,
  parseJsonl,
  parseLegacy,
  parseLogLine,
  parseLogLines,
};
