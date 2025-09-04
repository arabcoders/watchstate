type GenericError = {
    error: {
        code: number
        message: string
    }
}
type GenericResponse = {
    info: {
        code: number
        message: string
    }
}

export type {GenericError, GenericResponse}
