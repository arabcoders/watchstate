const makeName = id => id.split('-').slice(0)[0]

const getStatusClass = status => {
    switch (status) {
        case 0:
            return 'is-light'
        case 1:
            return 'is-warning'
        case 2:
            return 'is-success'
        case 3:
            return 'is-danger'
        case 4:
            return 'is-danger is-light'
        default:
            return 'is-light'
    }
}

export {makeName, getStatusClass}
