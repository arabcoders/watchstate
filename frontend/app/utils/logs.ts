import type { ServerJsonLogEntry, ServerJsonLogException, ServerJsonLogStackFrame } from '~/types';

export type LogLevel = 'debug' | 'info' | 'notice' | 'warning' | 'error';

export type LogTone = 'neutral' | 'info' | 'warning' | 'error' | 'success';

export const LOG_LEVEL_ICON: Record<LogLevel, string> = {
  debug: 'i-lucide-terminal',
  info: 'i-lucide-info',
  notice: 'i-lucide-bell-ring',
  warning: 'i-lucide-triangle-alert',
  error: 'i-lucide-circle-x',
};

export const getLogLevel = (level: string): LogLevel => {
  const lc = level.toLowerCase();
  if (lc === 'info') {
    return 'info';
  }
  if (lc === 'notice') {
    return 'notice';
  }
  if (lc === 'warning' || lc === 'warn') {
    return 'warning';
  }
  if (
    lc === 'error' ||
    lc === 'critical' ||
    lc === 'fatal' ||
    lc === 'alert' ||
    lc === 'emergency'
  ) {
    return 'error';
  }
  return 'debug';
};

export const logLevelBadgeClass = (level: LogLevel): string[] => [
  'inline-flex w-24 items-center gap-1.5 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide cursor-pointer whitespace-nowrap',
  level === 'debug' ? 'bg-muted/40 text-muted' : '',
  level === 'info' ? 'bg-info/10 text-info' : '',
  level === 'notice' ? 'bg-primary/12 text-primary' : '',
  level === 'warning' ? 'bg-warning/10 text-warning' : '',
  level === 'error' ? 'bg-error/10 text-error' : '',
];

export const logTimeLabel = (value: string): string => {
  if (!value) {
    return '00:00:00';
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

export const logTimeTitle = (value: string): string => {
  if (!value) {
    return 'No timestamp';
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }
  return date.toLocaleString();
};

export const lineTone = (text: string): LogTone => {
  const value = text.toLowerCase();
  if (/(error|fatal|panic|exception|traceback|failed)/.test(value)) {
    return 'error';
  }
  if (/(warn|warning|deprecated)/.test(value)) {
    return 'warning';
  }
  if (/(success|started|listening|connected|ready|complete|done)/.test(value)) {
    return 'success';
  }
  if (/(info|debug|trace)/.test(value)) {
    return 'info';
  }
  return 'neutral';
};

export const toneDotClass = (tone: LogTone): string => {
  switch (tone) {
    case 'error':
      return 'bg-error';
    case 'warning':
      return 'bg-warning';
    case 'success':
      return 'bg-success';
    case 'info':
      return 'bg-info';
    default:
      return 'bg-muted';
  }
};

export const toneTextClass = (tone: LogTone): string => {
  switch (tone) {
    case 'error':
      return 'text-error';
    case 'warning':
      return 'text-warning';
    case 'success':
      return 'text-success';
    case 'info':
      return 'text-info';
    default:
      return 'text-default';
  }
};

const parseLogLineNumber = (value: unknown): number | string | undefined => {
  if (typeof value === 'number') {
    return value;
  }
  if (typeof value === 'string') {
    const trimmed = value.trim();
    if (trimmed.length > 0) {
      return Number.isNaN(Number(trimmed)) ? trimmed : Number(trimmed);
    }
  }
  return undefined;
};

export const normalizeStructuredEntry = (value: unknown): ServerJsonLogEntry | null => {
  if (typeof value === 'string') {
    try {
      return normalizeStructuredEntry(JSON.parse(value));
    } catch {
      return null;
    }
  }

  if (!value || Array.isArray(value) || typeof value !== 'object') {
    return null;
  }

  const payload = value as Record<string, unknown>;
  const id = typeof payload.id === 'string' ? payload.id.trim() : '';
  const datetime = typeof payload.datetime === 'string' ? payload.datetime.trim() : '';
  const level = typeof payload.level === 'string' ? payload.level.trim() : '';
  const logger = typeof payload.logger === 'string' ? payload.logger.trim() : '';
  const message = typeof payload.message === 'string' ? payload.message.trim() : '';

  if (!id || !datetime || !level || !message) {
    return null;
  }

  const entry: ServerJsonLogEntry = { id, datetime, level, logger, message };

  if (typeof payload.levelno === 'number') {
    entry.levelno = payload.levelno;
  }

  if (payload.source && typeof payload.source === 'object' && !Array.isArray(payload.source)) {
    entry.source = payload.source as ServerJsonLogEntry['source'];
  }

  if (payload.process && typeof payload.process === 'object' && !Array.isArray(payload.process)) {
    entry.process = payload.process as ServerJsonLogEntry['process'];
  }

  if (payload.fields && typeof payload.fields === 'object' && !Array.isArray(payload.fields)) {
    entry.fields = payload.fields as Record<string, unknown>;
  }

  if (payload.exception === null) {
    entry.exception = null;
  } else if (
    payload.exception &&
    typeof payload.exception === 'object' &&
    !Array.isArray(payload.exception)
  ) {
    const exPayload = payload.exception as Record<string, unknown>;
    const exception: ServerJsonLogException = { ...exPayload };
    if (typeof exPayload.type === 'string') {
      exception.type = exPayload.type.trim();
    }
    if (typeof exPayload.message === 'string') {
      exception.message = exPayload.message.trim();
    }
    if (typeof exPayload.file === 'string') {
      exception.file = exPayload.file.trim();
    }
    const line = parseLogLineNumber(exPayload.line);
    if (line !== undefined) {
      exception.line = line;
    }
    if (Array.isArray(exPayload.trace)) {
      exception.trace = exPayload.trace as Array<ServerJsonLogStackFrame>;
    } else if (exPayload.trace === null) {
      exception.trace = null;
    }
    entry.exception = exception;
  }

  return entry;
};
