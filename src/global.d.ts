declare global {
  interface Window {
    ACMS: {
      Ready,
      Config: {
        root: string,
        bid?: string | number,
        LiteEditorSourceModeTags?: RegExp,
        LiteEditorConf: {
          btnOptions: {
            label: string,
            group: string,
            action: string,
            onClick:(editor: any) => void
          }[]
        }
      }
      addListener: any
    }
    csrfToken: string
  }
}

export {};
