const makeName = (id: string | number): string => String(id).replace(/-/g, '').slice(0, 12)

const getStatusClass = (status: number): string => {
    switch (status) {
        case 0:
            return 'is-light has-text-dark'
        case 1:
            return 'is-warning'
        case 2:
            return 'is-success'
        case 3:
            return 'is-danger'
        case 4:
            return 'is-danger is-light'
        default:
            return 'is-light has-text-dark'
    }
}

export {makeName, getStatusClass}
