/*
 * API contracts: the single TypeScript description of what the Laravel resources return.
 * Each type mirrors one PHP resource (named in its comment); keep them in sync with
 * tests/Feature/FrontendContractTest.php, which asserts the same shapes server-side.
 */

export type Platform = 'Steam' | 'PlayStation' | 'Xbox';
export type OrderStatus = 'pending' | 'processing' | 'delivered' | 'failed' | 'cancelled';
export type FailureCode = 'out_of_stock' | 'reservation_missing' | 'key_integrity' | 'attempts_exhausted';
export type OutboxStatus = 'pending' | 'processing' | 'published' | 'dead_letter';
export type CatalogSort = 'featured' | 'price_asc' | 'price_desc' | 'name' | 'newest';

/** Laravel paginated resource collection: { data, links, meta }. */
export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}
export interface PaginationMeta {
    current_page: number;
    from: number | null;
    last_page: number;
    links: PaginationLink[];
    path: string;
    per_page: number;
    to: number | null;
    total: number;
}
export interface Paginated<T> {
    data: T[];
    links: { first: string | null; last: string | null; prev: string | null; next: string | null };
    meta: PaginationMeta;
}

/** App\Http\Resources\GameResource */
export interface Game {
    slug: string;
    name: string;
    platform: Platform;
    genre: string;
    price_cents: number;
    currency: string;
    cover_url: string | null;
    description: string | null;
    is_featured: boolean;
    stock?: number;
}

/** App\Services\MarketPriceService::forGame() */
export type MarketPrice =
    | {
          status: 'available';
          lowest: number;
          retail: number | null;
          savings_percent: number | null;
          deal_count: number;
          currency: string;
          source: string;
          scope: string;
          fetched_at: string;
      }
    | { status: 'unavailable' | 'not_applicable'; reason: string; message: string };

/** App\Http\Resources\OrderResource */
export interface Order {
    id: string;
    status: OrderStatus;
    price_cents: number;
    currency: string;
    game?: { name: string; slug: string; platform: Platform; cover_url: string | null };
    failure_message: string | null;
    can_reveal: boolean;
    created_at: string;
    delivered_at: string | null;
    timeline?: { status: OrderStatus; at: string }[];
}

/** App\Http\Resources\Admin\AdminOrderResource */
export interface AdminOrder {
    id: number;
    public_id: string;
    status: OrderStatus;
    failure_code: FailureCode | null;
    retryable: boolean;
    attempts: number;
    lease_expires_at: string | null;
    price_cents: number;
    currency: string;
    user?: { id: number; name: string; email: string } | null;
    game?: { id: number; name: string; slug: string };
    key?: { id: number; status: 'available' | 'reserved' | 'sold'; reserved_at: string | null; sold_at: string | null } | null;
    created_at: string;
    processing_started_at: string | null;
    delivered_at: string | null;
    failed_at: string | null;
    cancelled_at: string | null;
}

/** Admin order timeline entry (Admin\OrderController@show). */
export interface AdminTimelineEntry {
    type: string;
    from: string | null;
    to: string | null;
    actor: string | null;
    meta: Record<string, unknown> | null;
    at: string;
}

/** App\Http\Resources\Admin\InventoryGameResource */
export interface InventoryGame {
    slug: string;
    name: string;
    platform: Platform;
    is_active: boolean;
    available: number;
    reserved: number;
    sold: number;
}

/** App\Http\Resources\Admin\OutboxMessageResource */
export interface OutboxMessage {
    id: number;
    event_id: string;
    type: string;
    status: OutboxStatus;
    attempts: number;
    available_at: string;
    locked_until: string | null;
    published_at: string | null;
    dead_lettered_at: string | null;
    last_error: string | null;
    created_at: string;
    updated_at: string;
}

/** App\Services\Outbox\OutboxPublisher::stats() */
export interface OutboxStats {
    counts: Record<OutboxStatus, number>;
    oldest_due_seconds: number | null;
    expired_leases: number;
}

/** JSON error body produced by bootstrap/app.php and the rate limiters. */
export interface ApiError {
    message: string;
    error?: string;
    errors?: Record<string, string[]>;
    request_id?: string;
}
