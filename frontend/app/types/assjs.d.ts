declare module 'assjs' {
  type AssRendererInstance = {
    destroy(): unknown;
    show(): unknown;
  };

  type AssRendererConstructor = new (
    content: string,
    video: HTMLVideoElement,
    options: { container: HTMLElement; resampling: 'video_height' },
  ) => AssRendererInstance;

  const Ass: AssRendererConstructor;

  export default Ass;
}
