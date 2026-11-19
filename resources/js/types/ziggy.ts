import { Config, RouteParams } from 'ziggy-js';

/** Ziggy accepts a single scalar for a route with one parameter, e.g. route('games.show', slug). */
type ScalarRouteParam = string | number;

declare global {
    function route(): Config;
    function route(name: string, params?: RouteParams<typeof name> | ScalarRouteParam | ScalarRouteParam[] | undefined, absolute?: boolean): string;
}

// Vue 3.3+ expects template globals to be declared on 'vue' (not '@vue/runtime-core').
declare module 'vue' {
    interface ComponentCustomProperties {
        route: typeof route;
    }
}

export {};
