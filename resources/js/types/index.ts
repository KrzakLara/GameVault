import type { PageProps as InertiaPageProps } from '@inertiajs/core';
import type { LucideIcon } from 'lucide-vue-next';

export * from './models';

/** Shared with every page by App\Http\Middleware\HandleInertiaRequests (a whitelist). */
export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    /** Display hint only: every admin route is enforced server-side. */
    is_admin: boolean;
}

export interface Auth {
    user: User | null;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash: { success: string | null; error: string | null };
    // `errors` is declared by Inertia's own PageProps (Errors & ErrorBag).
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

/** Page props of an authenticated page: `auth.user` is guaranteed by the route's auth middleware. */
export type AuthenticatedPageProps = InertiaPageProps & { auth: { user: User } };

// Type `$page.props` and usePage() everywhere with the shared props.
declare module '@inertiajs/core' {
    interface PageProps extends InertiaPageProps, SharedData {}
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export type BreadcrumbItemType = BreadcrumbItem;
