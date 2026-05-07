type FullscreenCapableElement = HTMLElement & {
  webkitRequestFullscreen?: () => Promise<void> | void;
};

type FullscreenCapableDocument = Document & {
  webkitFullscreenElement?: Element | null;
  webkitExitFullscreen?: () => Promise<void> | void;
};

export function getFullscreenElement(doc: Document = document): Element | null {
  const fullscreenDocument = doc as FullscreenCapableDocument;
  return fullscreenDocument.fullscreenElement || fullscreenDocument.webkitFullscreenElement || null;
}

export function canRequestFullscreen(
  element: HTMLElement | null | undefined,
): element is HTMLElement {
  return Boolean(
    element &&
    (typeof element.requestFullscreen === 'function' ||
      typeof (element as FullscreenCapableElement).webkitRequestFullscreen === 'function'),
  );
}

export async function requestElementFullscreen(element: HTMLElement): Promise<void> {
  const fullscreenElement = element as FullscreenCapableElement;
  if (typeof fullscreenElement.requestFullscreen === 'function') {
    await fullscreenElement.requestFullscreen();
    return;
  }

  if (typeof fullscreenElement.webkitRequestFullscreen === 'function') {
    await fullscreenElement.webkitRequestFullscreen();
    return;
  }

  throw new Error('Fullscreen API unavailable');
}

export async function exitDocumentFullscreen(doc: Document = document): Promise<void> {
  const fullscreenDocument = doc as FullscreenCapableDocument;
  if (typeof fullscreenDocument.exitFullscreen === 'function') {
    await fullscreenDocument.exitFullscreen();
    return;
  }

  if (typeof fullscreenDocument.webkitExitFullscreen === 'function') {
    await fullscreenDocument.webkitExitFullscreen();
    return;
  }

  throw new Error('Fullscreen API unavailable');
}
