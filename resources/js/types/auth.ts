/**
 * What `HandleInertiaRequests` shares about the signed-in operator.
 */
export type User = {
    id: number;
    name: string;
    email: string;
    role: string;
};

export type Auth = {
    user: User | null;
};
