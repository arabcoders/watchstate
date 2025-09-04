interface AuthState {
    token: string | null;
    authenticated: boolean;
    loading: boolean;
    username: string | null;
}

interface AuthActions {
    has_user(): Promise<boolean>;

    signup(username: string, password: string): Promise<boolean>;

    login(username: string, password: string): Promise<void>;

    logout(): Promise<boolean>;

    validate(token: string): Promise<boolean>;
}

export type {AuthState, AuthActions};
