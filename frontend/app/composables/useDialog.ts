import {reactive, readonly} from 'vue'

export type DialogResult<T = string | null> = { status: boolean; value: T }

type BaseOptions = {
    /**
     * Title of the dialog
     */
    title?: string
    /**
     * Message to display in the dialog
     */
    message?: string
    /**
     * Text for the confirm button
     */
    confirmText?: string
    /**
     * Color class for the confirm button (e.g., 'is-primary', 'is-danger')
     */
    confirmColor?: 'is-danger' | 'is-primary' | 'is-link' | 'is-info' | 'is-success' | 'is-warning' | 'is-light' | 'is-dark' | 'is-white',
    /**
     * No opacity control.
     */
    opacityControl?: boolean,
}

export type PromptOptions = BaseOptions & {
    /**
     * Text for the input field
     */
    initial?: string
    /**
     * Placeholder text for the input field
     */
    placeholder?: string
    /**
     * Text for the cancel button
     */
    cancelText?: string
    /**
     * Function to validate the input value
     * @returns true if valid, or an error message string if invalid
     */
    validate?: (v: string) => true | string
}

export type ConfirmOptions = BaseOptions & {
    /**
     * Text for the confirm button
     */
    cancelText?: string
    /**
     * Raw HTML content to include in the dialog message.
     */
    rawHTML?: string
}

export type AlertOptions = BaseOptions & {}

export type QueueItem = {
    type: 'prompt' | 'confirm' | 'alert'
    opts: PromptOptions | ConfirmOptions | AlertOptions
    resolve: (r: DialogResult<any>) => void
}

type DialogState = {
    current: QueueItem | null
    queue: QueueItem[]
    errorMsg: string | null
    input: string
}

export const useDialog = () => {
    const raw = useState<DialogState>('dialog:state', () => reactive({
        current: null,
        queue: [],
        errorMsg: null,
        input: '',
    } as DialogState))

    const state = raw.value

    const _dequeue = () => {
        if (!state.current && state.queue.length) {
            state.current = state.queue.shift()!
            state.errorMsg = null
            state.input = state.current.type === 'prompt' ? (state.current.opts as PromptOptions).initial ?? '' : ''
        }
    }

    const promptDialog = (opts: PromptOptions) => new Promise<DialogResult<string>>((resolve) => {
        state.queue.push({type: 'prompt', opts, resolve})
        _dequeue()
    })

    const confirmDialog = (opts: ConfirmOptions) => new Promise<DialogResult<null>>((resolve) => {
        state.queue.push({type: 'confirm', opts, resolve})
        _dequeue()
    })

    const alertDialog = (opts: AlertOptions) => new Promise<DialogResult<null>>((resolve) => {
        state.queue.push({type: 'alert', opts, resolve})
        _dequeue()
    })

    const confirm = (value?: string) => {
        if (!state.current) return
        if (state.current.type === 'prompt') {
            const val = value ?? state.input
            const v = (state.current.opts as PromptOptions).validate?.(val)
            if (v && v !== true) {
                state.errorMsg = v
                return
            }
            state.current.resolve({status: true, value: val})
        } else {
            state.current.resolve({status: true, value: null})
        }
        state.current = null
        _dequeue()
    }

    const cancel = () => {
        if (!state.current) return
        state.current.resolve({status: false, value: null})
        state.current = null
        _dequeue()
    }

    return {
        promptDialog,
        confirmDialog,
        alertDialog,
        confirm,
        cancel,
        state: readonly(state) as Readonly<DialogState>,
    }
}
