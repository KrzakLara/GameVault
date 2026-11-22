/**
 * Original, procedurally generated cover art for GameVault's fictional catalogue.
 *
 * Every genre has its own illustrated scene (racing: synthwave road, RPG: moonlit citadel,
 * shooter: night city under a targeting HUD, strategy: hex territory map, adventure: layered
 * valleys at sunset, action: energy burst, indie: ringed planet over a pastel sky). A seed
 * derived from the slug picks the palette and varies the composition, so a game always gets the
 * same cover and no two games look alike. Portrait (4:5) and landscape (16:9) formats share the
 * seed, so both crops of a title belong together.
 *
 * The result is plain data (SVG element descriptions) that GameCover renders inline: no image
 * files, no network requests, no v-html. All geometry is original; nothing imitates a real game,
 * logo, publisher or storefront.
 */

export interface SvgNode {
    tag: string;
    attrs: Record<string, string | number>;
    children?: SvgNode[];
    text?: string;
}

export interface CoverArt {
    width: number;
    height: number;
    defs: SvgNode[];
    layers: SvgNode[];
}

export interface CoverOptions {
    slug: string;
    name: string;
    genre?: string | null;
    format: 'portrait' | 'landscape';
    /** Unique per rendered instance: gradient ids must not collide when a game appears twice on a page. */
    idPrefix: string;
    /** Paint the title logotype onto the art (portrait only). */
    title: boolean;
}

type Rng = () => number;
type Stop = [offset: number, color: string, opacity?: number];

interface Scene {
    rng: Rng;
    w: number;
    h: number;
    /** One hundredth of the shorter side: keeps details proportional in both formats. */
    u: number;
    id: (name: string) => string;
    defs: SvgNode[];
    layers: SvgNode[];
}

const SIZES = { portrait: [400, 500], landscape: [960, 540] } as const;

export function hash(input: string): number {
    let h = 2166136261;
    for (let i = 0; i < input.length; i++) {
        h ^= input.charCodeAt(i);
        h = Math.imul(h, 16777619);
    }

    return h >>> 0;
}

function mulberry32(seed: number): Rng {
    let a = seed;

    return () => {
        a = (a + 0x6d2b79f5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

const r1 = (n: number) => Math.round(n * 10) / 10;
const between = (rng: Rng, min: number, max: number) => min + (max - min) * rng();
const pick = <T>(rng: Rng, items: readonly T[]): T => items[Math.floor(rng() * items.length)];

function el(tag: string, attrs: Record<string, string | number>, children?: SvgNode[]): SvgNode {
    return children ? { tag, attrs, children } : { tag, attrs };
}

function mix(a: string, b: string, t: number): string {
    const pa = parseInt(a.slice(1), 16);
    const pb = parseInt(b.slice(1), 16);
    const channel = (shift: number) => {
        const ca = (pa >> shift) & 255;
        const cb = (pb >> shift) & 255;

        return Math.round(ca + (cb - ca) * t);
    };

    return `#${((1 << 24) | (channel(16) << 16) | (channel(8) << 8) | channel(0)).toString(16).slice(1)}`;
}

function gradientStops(stops: Stop[]): SvgNode[] {
    return stops.map(([offset, color, opacity = 1]) => el('stop', { offset, 'stop-color': color, 'stop-opacity': opacity }));
}

/** Registers a linear gradient (vertical by default) and returns its fill reference. */
function linear(s: Scene, name: string, stops: Stop[], dir: Record<string, number> = { x1: 0, y1: 0, x2: 0, y2: 1 }, userSpace = false): string {
    s.defs.push(el('linearGradient', { id: s.id(name), ...dir, ...(userSpace ? { gradientUnits: 'userSpaceOnUse' } : {}) }, gradientStops(stops)));

    return `url(#${s.id(name)})`;
}

/** Registers a radial gradient in user space (pixel coordinates) and returns its fill reference. */
function radial(s: Scene, name: string, stops: Stop[], at: { cx: number; cy: number; r: number }): string {
    s.defs.push(
        el('radialGradient', { id: s.id(name), cx: r1(at.cx), cy: r1(at.cy), r: r1(at.r), gradientUnits: 'userSpaceOnUse' }, gradientStops(stops)),
    );

    return `url(#${s.id(name)})`;
}

function fillCanvas(s: Scene, fill: string, extra: Record<string, string | number> = {}): void {
    s.layers.push(el('rect', { x: 0, y: 0, width: s.w, height: s.h, fill, ...extra }));
}

/** A ridge line closed down to `bottom`: jagged peaks, or smooth rolling hills. */
function ridge(rng: Rng, w: number, bottom: number, base: number, amp: number, count: number, smooth: boolean): string {
    const pts: [number, number][] = [];
    for (let i = 0; i <= count; i++) {
        const lift = smooth ? 0.25 + 0.75 * rng() : i % 2 === 0 ? 0.1 + 0.4 * rng() : 0.55 + 0.45 * rng();
        pts.push([(i / count) * w, base - amp * lift]);
    }

    let d = `M0 ${r1(bottom)} L0 ${r1(pts[0][1])}`;
    if (smooth) {
        for (let i = 1; i < pts.length - 1; i++) {
            const [x, y] = pts[i];
            const [nx, ny] = pts[i + 1];
            d += ` Q${r1(x)} ${r1(y)} ${r1((x + nx) / 2)} ${r1((y + ny) / 2)}`;
        }
        d += ` L${r1(w)} ${r1(pts[pts.length - 1][1])}`;
    } else {
        for (let i = 1; i < pts.length; i++) {
            d += ` L${r1(pts[i][0])} ${r1(pts[i][1])}`;
        }
    }

    return `${d} L${r1(w)} ${r1(bottom)} Z`;
}

function stars(s: Scene, count: number, maxY: number): void {
    const dots: string[] = [];
    for (let i = 0; i < count; i++) {
        const x = between(s.rng, 0, s.w);
        const y = between(s.rng, 0, maxY);
        const r = between(s.rng, 0.15, 0.55) * s.u;
        dots.push(`M${r1(x - r)} ${r1(y)} a${r1(r)} ${r1(r)} 0 1 0 ${r1(r * 2)} 0 a${r1(r)} ${r1(r)} 0 1 0 ${r1(-r * 2)} 0`);
    }
    s.layers.push(el('path', { d: dots.join(' '), fill: '#ffffff', opacity: 0.75 }));
}

// --- Racing: synthwave sun over a neon grid and an endless road -------------------------------

const RACING = [
    {
        top: '#160a35',
        mid: '#5b1a7a',
        low: '#ff6a5c',
        sunA: '#ffe08a',
        sunB: '#ff3d8b',
        grid: '#ff4fd8',
        ground: '#0e0622',
        ridge: '#2a0d4a',
        glow: '#ff6ad5',
    },
    {
        top: '#030a2a',
        mid: '#1b2f8f',
        low: '#22d3ee',
        sunA: '#f0fdff',
        sunB: '#38bdf8',
        grid: '#22d3ee',
        ground: '#040b22',
        ridge: '#0c1d55',
        glow: '#67e8f9',
    },
    {
        top: '#110424',
        mid: '#4c1d95',
        low: '#e879f9',
        sunA: '#fef3c7',
        sunB: '#f472b6',
        grid: '#a78bfa',
        ground: '#0b041a',
        ridge: '#24104a',
        glow: '#c4b5fd',
    },
] as const;

function racing(s: Scene): string {
    const p = pick(s.rng, RACING);
    const { w, h, u, rng } = s;
    const horizon = h * 0.6;

    fillCanvas(
        s,
        linear(
            s,
            'sky',
            [
                [0, p.top],
                [0.55, p.mid],
                [1, p.low],
            ],
            { x1: 0, y1: 0, x2: 0, y2: horizon },
            true,
        ),
    );
    stars(s, Math.round((w * h) / 9000), horizon * 0.5);

    const sunR = Math.min(w, h) * between(rng, 0.2, 0.26);
    const sunX = w * between(rng, 0.4, 0.6);
    const sunY = horizon - sunR * 0.3;
    s.layers.push(
        el('circle', {
            cx: r1(sunX),
            cy: r1(sunY),
            r: r1(sunR * 2.4),
            fill: radial(
                s,
                'sunGlow',
                [
                    [0, p.glow, 0.55],
                    [1, p.glow, 0],
                ],
                { cx: sunX, cy: sunY, r: sunR * 2.4 },
            ),
        }),
    );
    const cuts: SvgNode[] = [el('rect', { x: 0, y: 0, width: w, height: h, fill: '#ffffff' })];
    for (let k = 0; k < 7; k++) {
        cuts.push(el('rect', { x: 0, y: r1(sunY + sunR * (0.02 + k * 0.14)), width: w, height: r1(u * (0.5 + k * 0.45)), fill: '#000000' }));
    }
    s.defs.push(el('mask', { id: s.id('sunCut'), maskUnits: 'userSpaceOnUse', x: 0, y: 0, width: w, height: h }, cuts));
    s.layers.push(
        el('circle', {
            cx: r1(sunX),
            cy: r1(sunY),
            r: r1(sunR),
            fill: linear(s, 'sun', [
                [0, p.sunA],
                [1, p.sunB],
            ]),
            mask: `url(#${s.id('sunCut')})`,
        }),
    );

    for (let k = 0; k < 4; k++) {
        const y = horizon * between(rng, 0.3, 0.8);
        s.layers.push(
            el('rect', {
                x: r1(between(rng, -w * 0.1, w * 0.75)),
                y: r1(y),
                width: r1(w * between(rng, 0.12, 0.3)),
                height: r1(u * 0.3),
                rx: 1,
                fill: p.glow,
                opacity: r1(between(rng, 0.15, 0.35)),
            }),
        );
    }

    const far = ridge(rng, w, horizon + 2, horizon, h * 0.15, 10, false);
    s.layers.push(el('path', { d: far, fill: p.ridge }));
    s.layers.push(el('path', { d: far, fill: 'none', stroke: p.grid, 'stroke-width': r1(u * 0.35), 'stroke-opacity': 0.75 }));
    s.layers.push(el('path', { d: ridge(rng, w, horizon + 2, horizon, h * 0.06, 16, false), fill: mix(p.ridge, p.ground, 0.5) }));

    s.layers.push(el('rect', { x: 0, y: r1(horizon), width: w, height: r1(h - horizon), fill: p.ground }));
    const vx = w / 2;
    const grid: string[] = [];
    for (let i = -12; i <= 12; i++) {
        grid.push(`M${r1(vx + i * w * 0.012)} ${r1(horizon)} L${r1(vx + i * w * 0.16)} ${r1(h)}`);
    }
    for (let j = 1; j <= 10; j++) {
        grid.push(`M0 ${r1(horizon + (h - horizon) * Math.pow(j / 10, 2.1))} H${w}`);
    }
    const gridStroke = linear(
        s,
        'grid',
        [
            [0, p.grid, 0],
            [0.3, p.grid, 0.4],
            [1, p.grid, 0.95],
        ],
        { x1: 0, y1: horizon, x2: 0, y2: h },
        true,
    );
    s.layers.push(el('path', { d: grid.join(' '), fill: 'none', stroke: gridStroke, 'stroke-width': r1(u * 0.3) }));

    const top = w * 0.01;
    const bottom = w * 0.3;
    s.layers.push(
        el('path', {
            d: `M${r1(vx - top)} ${r1(horizon)} L${r1(vx + top)} ${r1(horizon)} L${r1(vx + bottom)} ${h} L${r1(vx - bottom)} ${h} Z`,
            fill: '#05020c',
            opacity: 0.9,
        }),
    );
    s.layers.push(
        el('path', {
            d: `M${r1(vx - top)} ${r1(horizon)} L${r1(vx - bottom)} ${h} M${r1(vx + top)} ${r1(horizon)} L${r1(vx + bottom)} ${h}`,
            fill: 'none',
            stroke: p.glow,
            'stroke-width': r1(u * 0.5),
        }),
    );
    const dashes: string[] = [];
    for (let k = 0; k < 6; k++) {
        const t0 = Math.pow((k + 0.2) / 6, 2);
        const t1 = Math.pow((k + 0.55) / 6, 2);
        const y0 = horizon + (h - horizon) * t0;
        const y1 = horizon + (h - horizon) * t1;
        const a = u * (0.12 + t0 * 0.9);
        const b = u * (0.12 + t1 * 0.9);
        dashes.push(`M${r1(vx - a)} ${r1(y0)} L${r1(vx + a)} ${r1(y0)} L${r1(vx + b)} ${r1(y1)} L${r1(vx - b)} ${r1(y1)} Z`);
    }
    s.layers.push(el('path', { d: dashes.join(' '), fill: p.sunA, opacity: 0.85 }));

    return p.grid;
}

// --- RPG: a citadel on a cliff under a moon, mountains fading into mist -----------------------

const RPG = [
    {
        top: '#050a22',
        mid: '#15245c',
        low: '#3d5fae',
        moon: '#eaf1ff',
        far: '#273c78',
        mid2: '#16224a',
        near: '#080d22',
        window: '#ffd27a',
        aurora: '#5eead4',
        banner: '#f472b6',
    },
    {
        top: '#03121a',
        mid: '#0a3a3e',
        low: '#1f7a6b',
        moon: '#e9fff8',
        far: '#1b544f',
        mid2: '#0e3431',
        near: '#051716',
        window: '#fcd34d',
        aurora: '#86efac',
        banner: '#fbbf24',
    },
    {
        top: '#14051a',
        mid: '#480f2e',
        low: '#b4374f',
        moon: '#ffe8ec',
        far: '#561c39',
        mid2: '#2c0c21',
        near: '#12040d',
        window: '#fdba74',
        aurora: '#f9a8d4',
        banner: '#fde68a',
    },
] as const;

function rpg(s: Scene): string {
    const p = pick(s.rng, RPG);
    const { w, h, u, rng } = s;

    fillCanvas(
        s,
        linear(s, 'sky', [
            [0, p.top],
            [0.6, p.mid],
            [1, p.low],
        ]),
    );
    stars(s, Math.round((w * h) / 4500), h * 0.55);

    if (rng() < 0.6) {
        const band = linear(
            s,
            'aurora',
            [
                [0, p.aurora, 0],
                [0.5, p.aurora, 0.4],
                [1, p.aurora, 0],
            ],
            { x1: 0, y1: 0, x2: 1, y2: 0 },
        );
        for (let k = 0; k < 2; k++) {
            const y = h * (0.12 + k * 0.1) + between(rng, -h * 0.03, h * 0.03);
            const a = h * 0.06;
            s.layers.push(
                el('path', {
                    d: `M${r1(-w * 0.1)} ${r1(y)} C${r1(w * 0.25)} ${r1(y - a)} ${r1(w * 0.5)} ${r1(y + a)} ${r1(w * 1.1)} ${r1(y - a * 0.5)} L${r1(w * 1.1)} ${r1(y + a * 0.9)} C${r1(w * 0.5)} ${r1(y + a * 1.8)} ${r1(w * 0.25)} ${r1(y + a * 0.2)} ${r1(-w * 0.1)} ${r1(y + a)} Z`,
                    fill: band,
                    opacity: r1(0.6 - k * 0.2),
                }),
            );
        }
    }

    const moonLeft = rng() < 0.5;
    const mx = w * (moonLeft ? between(rng, 0.16, 0.3) : between(rng, 0.7, 0.84));
    const my = h * between(rng, 0.14, 0.22);
    const mr = Math.min(w, h) * between(rng, 0.075, 0.1);
    s.layers.push(
        el('circle', {
            cx: r1(mx),
            cy: r1(my),
            r: r1(mr * 3.6),
            fill: radial(
                s,
                'moonGlow',
                [
                    [0, p.moon, 0.32],
                    [1, p.moon, 0],
                ],
                { cx: mx, cy: my, r: mr * 3.6 },
            ),
        }),
    );
    s.layers.push(el('circle', { cx: r1(mx), cy: r1(my), r: r1(mr), fill: p.moon }));
    for (let k = 0; k < 3; k++) {
        s.layers.push(
            el('circle', {
                cx: r1(mx + between(rng, -0.45, 0.45) * mr),
                cy: r1(my + between(rng, -0.45, 0.45) * mr),
                r: r1(mr * between(rng, 0.1, 0.2)),
                fill: p.far,
                opacity: 0.2,
            }),
        );
    }

    s.layers.push(el('path', { d: ridge(rng, w, h, h * 0.62, h * 0.22, 7, false), fill: p.far }));
    s.layers.push(el('ellipse', { cx: r1(w / 2), cy: r1(h * 0.64), rx: r1(w * 0.75), ry: r1(h * 0.05), fill: '#ffffff', opacity: 0.07 }));
    s.layers.push(el('path', { d: ridge(rng, w, h, h * 0.74, h * 0.13, 11, false), fill: p.mid2 }));

    // The citadel is drawn for the left side and mirrored when the moon sits on the left.
    const plateau = h * between(rng, 0.54, 0.58);
    const cw = w * (w > h ? 0.3 : 0.5);
    const unit = u * (w > h ? 1.1 : 1.25);
    const cx = cw * 0.45;
    const parts: SvgNode[] = [
        el('path', {
            d: `M0 ${h} L0 ${r1(plateau)} L${r1(cw * 0.9)} ${r1(plateau)} L${r1(cw)} ${r1(plateau + h * 0.05)} L${r1(cw * 1.04)} ${r1(plateau + h * 0.16)} L${r1(cw * 1.14)} ${r1(h * 0.86)} L${r1(cw * 1.3)} ${h} Z`,
            fill: p.near,
        }),
        el('rect', { x: r1(cx - unit * 14), y: r1(plateau - unit * 8), width: r1(unit * 28), height: r1(unit * 8 + 1), fill: p.near }),
    ];
    for (let k = 0; k < 7; k++) {
        parts.push(
            el('rect', {
                x: r1(cx - unit * 14 + k * unit * 4.3),
                y: r1(plateau - unit * 9.6),
                width: r1(unit * 2.2),
                height: r1(unit * 1.8),
                fill: p.near,
            }),
        );
    }
    const towers: [number, number, number][] = [
        [-14, 5, 18],
        [-4, 7, 28 + between(rng, 0, 8)],
        [7, 5, 20],
        [12, 4, 14],
    ];
    const lit: string[] = [];
    for (const [ox, tw, th] of towers) {
        const tx = cx + ox * unit;
        const top = plateau - th * unit;
        parts.push(el('rect', { x: r1(tx), y: r1(top), width: r1(tw * unit), height: r1(th * unit + 1), fill: p.near }));
        parts.push(
            el('path', {
                d: `M${r1(tx - unit * 0.8)} ${r1(top)} L${r1(tx + (tw * unit) / 2)} ${r1(top - tw * unit * 1.3)} L${r1(tx + tw * unit + unit * 0.8)} ${r1(top)} Z`,
                fill: p.near,
            }),
        );
        for (let k = 0; k < 2; k++) {
            if (rng() < 0.75) {
                lit.push(`M${r1(tx + tw * unit * 0.36)} ${r1(top + unit * (3 + k * 5))} h${r1(unit * 1.2)} v${r1(unit * 2)} h${r1(-unit * 1.2)} Z`);
            }
        }
    }
    parts.push(el('path', { d: lit.join(' '), fill: p.window }));
    const [bx, bw, bh] = towers[1];
    const poleX = cx + bx * unit + (bw * unit) / 2;
    const poleTop = plateau - bh * unit - bw * unit * 1.3 - unit * 6;
    parts.push(el('path', { d: `M${r1(poleX)} ${r1(poleTop + unit * 6)} V${r1(poleTop)}`, stroke: p.near, 'stroke-width': r1(unit * 0.5) }));
    parts.push(
        el('path', {
            d: `M${r1(poleX)} ${r1(poleTop)} L${r1(poleX + unit * 4)} ${r1(poleTop + unit * 1.2)} L${r1(poleX)} ${r1(poleTop + unit * 2.4)} Z`,
            fill: p.banner,
        }),
    );
    s.layers.push(moonLeft ? el('g', { transform: `translate(${w} 0) scale(-1 1)` }, parts) : el('g', {}, parts));

    s.layers.push(el('ellipse', { cx: r1(w / 2), cy: r1(h * 0.86), rx: r1(w * 0.8), ry: r1(h * 0.06), fill: '#ffffff', opacity: 0.05 }));
    s.layers.push(el('path', { d: ridge(rng, w, h, h * 0.95, h * 0.07, 6, true), fill: p.near }));

    return p.banner;
}

// --- Shooter: a night skyline cut by searchlights, seen through a targeting HUD ---------------

const SHOOTER = [
    {
        top: '#020617',
        mid: '#0b2a3a',
        low: '#0e7490',
        beam: '#67e8f9',
        far: '#10303f',
        near: '#02060f',
        windows: '#7dd3fc',
        hud: '#22d3ee',
        alert: '#f43f5e',
    },
    {
        top: '#090206',
        mid: '#380c16',
        low: '#9f1239',
        beam: '#fda4af',
        far: '#36101c',
        near: '#060104',
        windows: '#fb7185',
        hud: '#fda4af',
        alert: '#fde047',
    },
    {
        top: '#06031a',
        mid: '#22114a',
        low: '#6d28d9',
        beam: '#c4b5fd',
        far: '#271a52',
        near: '#04020e',
        windows: '#a5b4fc',
        hud: '#a78bfa',
        alert: '#22d3ee',
    },
] as const;

function shooter(s: Scene): string {
    const p = pick(s.rng, SHOOTER);
    const { w, h, u, rng } = s;

    fillCanvas(
        s,
        linear(s, 'sky', [
            [0, p.top],
            [0.62, p.mid],
            [1, p.low],
        ]),
    );
    s.layers.push(
        el('ellipse', {
            cx: r1(w / 2),
            cy: r1(h * 0.95),
            rx: r1(w * 0.9),
            ry: r1(h * 0.45),
            fill: radial(
                s,
                'haze',
                [
                    [0, p.low, 0.55],
                    [1, p.low, 0],
                ],
                { cx: w / 2, cy: h * 0.95, r: w * 0.9 },
            ),
        }),
    );

    const beam = linear(
        s,
        'beam',
        [
            [0, p.beam, 0.38],
            [1, p.beam, 0],
        ],
        { x1: 0, y1: h, x2: 0, y2: 0 },
        true,
    );
    for (let k = 0; k < 3; k++) {
        const bx = w * between(rng, 0.1, 0.9);
        const tilt = between(rng, -0.45, 0.45) * w;
        const spread = w * between(rng, 0.04, 0.08);
        s.layers.push(
            el('path', {
                d: `M${r1(bx - u * 0.6)} ${r1(h * 0.8)} L${r1(bx + u * 0.6)} ${r1(h * 0.8)} L${r1(bx + tilt + spread)} -10 L${r1(bx + tilt - spread)} -10 Z`,
                fill: beam,
            }),
        );
    }

    const skyline = (base: number, minH: number, maxH: number, minW: number, maxW: number, fill: string) => {
        let x = -between(rng, 0, maxW);
        let d = '';
        const windows: string[] = [];
        while (x < w) {
            const bw = between(rng, minW, maxW);
            const bh = between(rng, minH, maxH);
            const top = base - bh;
            d += `M${r1(x)} ${r1(base)} V${r1(top)} H${r1(x + bw)} V${r1(base)} Z `;
            if (rng() < 0.3) {
                d += `M${r1(x + bw * 0.45)} ${r1(top)} v${r1(-bh * 0.18)} h${r1(u * 0.4)} v${r1(bh * 0.18)} Z `;
            }
            for (let wy = top + u * 2; wy < base - u * 2; wy += u * 2.6) {
                for (let wx = x + u * 1.2; wx < x + bw - u * 1.6; wx += u * 2) {
                    if (rng() < 0.16) {
                        windows.push(`M${r1(wx)} ${r1(wy)} h${r1(u * 0.8)} v${r1(u * 1.1)} h${r1(-u * 0.8)} Z`);
                    }
                }
            }
            x += bw + between(rng, 0, u * 1.2);
        }
        s.layers.push(el('path', { d, fill }));
        s.layers.push(el('path', { d: windows.join(' '), fill: p.windows, opacity: 0.75 }));
    };
    skyline(h * 0.84, h * 0.18, h * 0.46, u * 5, u * 11, p.far);
    skyline(h * 1.01, h * 0.12, h * 0.36, u * 8, u * 18, p.near);

    const hx = w * between(rng, 0.3, 0.7);
    const hy = h * between(rng, 0.22, 0.4);
    const R = Math.min(w, h) * 0.15;
    const g = R * 1.25;
    const stroke = { fill: 'none', stroke: p.hud, 'stroke-width': r1(u * 0.35) };
    s.layers.push(
        el('g', { opacity: 0.8 }, [
            el('circle', { cx: r1(hx), cy: r1(hy), r: r1(R), ...stroke, 'stroke-dasharray': `${r1(R * 0.5)} ${r1(R * 0.18)}` }),
            el('circle', { cx: r1(hx), cy: r1(hy), r: r1(R * 0.12), ...stroke }),
            el('path', {
                d: `M${r1(hx - R * 1.35)} ${r1(hy)} H${r1(hx - R * 0.35)} M${r1(hx + R * 0.35)} ${r1(hy)} H${r1(hx + R * 1.35)} M${r1(hx)} ${r1(hy - R * 1.35)} V${r1(hy - R * 0.35)} M${r1(hx)} ${r1(hy + R * 0.35)} V${r1(hy + R * 1.35)}`,
                ...stroke,
            }),
            el('path', {
                d: [
                    [-1, -1],
                    [1, -1],
                    [1, 1],
                    [-1, 1],
                ]
                    .map(([sx, sy]) => `M${r1(hx + sx * g)} ${r1(hy + sy * g * 0.7)} V${r1(hy + sy * g)} H${r1(hx + sx * g * 0.7)}`)
                    .join(' '),
                ...stroke,
                'stroke-width': r1(u * 0.55),
            }),
            el('circle', { cx: r1(hx + R * 0.55), cy: r1(hy - R * 0.55), r: r1(u * 0.9), fill: p.alert }),
        ]),
    );

    s.defs.push(
        el('pattern', { id: s.id('scan'), width: 4, height: 4, patternUnits: 'userSpaceOnUse' }, [
            el('rect', { x: 0, y: 0, width: 4, height: 1, fill: '#000000' }),
        ]),
    );
    fillCanvas(s, `url(#${s.id('scan')})`, { opacity: 0.22 });

    return p.hud;
}

// --- Strategy: a hex map with terrain and two rival territories -------------------------------

const STRATEGY = [
    { sea: '#0a3050', deep: '#031524', land: ['#1d6b58', '#2f8a6b', '#57a97c'], peak: '#d6e4b0', a: '#fbbf24', b: '#c084fc', grid: '#9ee6d2' },
    { sea: '#0d2848', deep: '#05122a', land: ['#7a4f25', '#a8743a', '#d4a259'], peak: '#f6e7c1', a: '#f43f5e', b: '#22d3ee', grid: '#f5d9a8' },
    { sea: '#0c2244', deep: '#050e22', land: ['#4e6c99', '#7e9cc6', '#bccde6'], peak: '#ffffff', a: '#f472b6', b: '#34d399', grid: '#c7dcff' },
] as const;

function strategy(s: Scene): string {
    const p = pick(s.rng, STRATEGY);
    const { w, h, u, rng } = s;

    fillCanvas(
        s,
        radial(
            s,
            'sea',
            [
                [0, p.sea],
                [1, p.deep],
            ],
            { cx: w * 0.5, cy: h * 0.4, r: Math.max(w, h) * 0.75 },
        ),
    );

    const blobs = Array.from({ length: 4 }, () => ({
        x: w * between(rng, 0.1, 0.9),
        y: h * between(rng, 0.12, 0.78),
        r: Math.min(w, h) * between(rng, 0.2, 0.36),
    }));
    const size = Math.min(w, h) / (w > h ? 14 : 12);
    const dx = size * 1.5;
    const dy = Math.sqrt(3) * size;
    const hex = (cx: number, cy: number, rad: number) => {
        let d = '';
        for (let k = 0; k < 6; k++) {
            const a = (Math.PI / 3) * k;
            d += `${k ? 'L' : 'M'}${r1(cx + rad * Math.cos(a))} ${r1(cy + rad * Math.sin(a))} `;
        }

        return `${d}Z `;
    };

    const sea: string[] = [];
    const terrain = new Map<string, string[]>();
    const cells: { cx: number; cy: number; v: number }[] = [];
    for (let col = -1; col * dx < w + size; col++) {
        for (let row = -1; row * dy < h + size; row++) {
            const cx = col * dx;
            const cy = row * dy + (Math.abs(col) % 2 ? dy / 2 : 0);
            const v = Math.max(...blobs.map((b) => 1 - Math.hypot(cx - b.x, cy - b.y) / b.r)) + between(rng, -0.08, 0.08);
            if (v < 0.1) {
                sea.push(hex(cx, cy, size * 0.94));
                continue;
            }
            const fill = v > 0.72 ? p.peak : v > 0.5 ? p.land[2] : v > 0.28 ? p.land[1] : p.land[0];
            terrain.set(fill, [...(terrain.get(fill) ?? []), hex(cx, cy, size * 0.92)]);
            cells.push({ cx, cy, v });
        }
    }
    s.layers.push(el('path', { d: sea.join(''), fill: 'none', stroke: p.grid, 'stroke-opacity': 0.08, 'stroke-width': r1(u * 0.25) }));
    for (const [fill, shapes] of terrain) {
        s.layers.push(el('path', { d: shapes.join(''), fill, stroke: p.grid, 'stroke-opacity': 0.18, 'stroke-width': r1(u * 0.25) }));
    }

    const land = cells.filter((c) => c.v > 0.2);
    for (const color of [p.a, p.b]) {
        if (!land.length) {
            break;
        }
        const capital = land[Math.floor(rng() * land.length)];
        const reach = size * between(rng, 2.2, 3.4);
        const zone = cells.filter((c) => Math.hypot(c.cx - capital.cx, c.cy - capital.cy) <= reach);
        s.layers.push(
            el('path', {
                d: zone.map((c) => hex(c.cx, c.cy, size * 0.92)).join(''),
                fill: color,
                'fill-opacity': 0.22,
                stroke: color,
                'stroke-width': r1(u * 0.45),
                'stroke-opacity': 0.9,
            }),
        );
        s.layers.push(
            el('path', {
                d: `M${r1(capital.cx)} ${r1(capital.cy + size * 0.35)} V${r1(capital.cy - size * 0.75)}`,
                stroke: '#0b0b10',
                'stroke-width': r1(u * 0.5),
            }),
        );
        s.layers.push(
            el('path', {
                d: `M${r1(capital.cx)} ${r1(capital.cy - size * 0.75)} L${r1(capital.cx + size * 0.6)} ${r1(capital.cy - size * 0.55)} L${r1(capital.cx)} ${r1(capital.cy - size * 0.35)} Z`,
                fill: color,
            }),
        );
    }

    fillCanvas(
        s,
        radial(
            s,
            'light',
            [
                [0, '#ffffff', 0.14],
                [1, '#ffffff', 0],
            ],
            { cx: w * 0.2, cy: h * 0.1, r: Math.max(w, h) * 0.8 },
        ),
    );

    return p.a;
}

// --- Adventure: layered valleys at golden hour with a river towards the sun -------------------

const ADVENTURE = [
    {
        top: '#2a1450',
        mid: '#b4477a',
        low: '#ffb36b',
        sun: '#fff1c1',
        hills: ['#a04f78', '#74336a', '#4a2152', '#2a1538', '#150a22'],
        river: '#ffd9a0',
    },
    {
        top: '#0b1b3a',
        mid: '#2a6f97',
        low: '#a7f0dc',
        sun: '#fffbe6',
        hills: ['#4a8b96', '#2f6a78', '#1d4a5a', '#11303f', '#081a26'],
        river: '#c8fff1',
    },
    {
        top: '#1c0e33',
        mid: '#7a2e6e',
        low: '#ff9aa8',
        sun: '#ffe6ec',
        hills: ['#a45486', '#743a6c', '#4b2452', '#2b153a', '#170b22'],
        river: '#ffd1dc',
    },
] as const;

function adventure(s: Scene): string {
    const p = pick(s.rng, ADVENTURE);
    const { w, h, u, rng } = s;

    const landmark = pick(rng, ['river', 'lake', 'ruins'] as const);
    fillCanvas(
        s,
        linear(s, 'sky', [
            [0, p.top],
            [0.5, p.mid],
            [0.9, p.low],
        ]),
    );
    const sx = w * between(rng, 0.25, 0.75);
    const sy = h * between(rng, 0.3, 0.48);
    const sr = Math.min(w, h) * between(rng, 0.08, 0.14);
    s.layers.push(
        el('circle', {
            cx: r1(sx),
            cy: r1(sy),
            r: r1(sr * 4.5),
            fill: radial(
                s,
                'sunGlow',
                [
                    [0, p.sun, 0.6],
                    [0.4, p.sun, 0.18],
                    [1, p.sun, 0],
                ],
                { cx: sx, cy: sy, r: sr * 4.5 },
            ),
        }),
    );
    s.layers.push(el('circle', { cx: r1(sx), cy: r1(sy), r: r1(sr), fill: p.sun }));
    for (let k = 0; k < 3; k++) {
        s.layers.push(
            el('rect', {
                x: r1(w * between(rng, -0.1, 0.6)),
                y: r1(h * between(rng, 0.14, 0.34)),
                width: r1(w * between(rng, 0.3, 0.55)),
                height: r1(u * between(rng, 0.8, 1.6)),
                rx: r1(u),
                fill: p.sun,
                opacity: 0.18,
            }),
        );
    }
    for (let k = 0; k < 4; k++) {
        const bx = w * between(rng, 0.15, 0.85);
        const by = h * between(rng, 0.16, 0.36);
        const bs = u * between(rng, 1.2, 2.2);
        s.layers.push(
            el('path', {
                d: `M${r1(bx - bs)} ${r1(by)} q${r1(bs * 0.5)} ${r1(-bs * 0.6)} ${r1(bs)} 0 q${r1(bs * 0.5)} ${r1(-bs * 0.6)} ${r1(bs)} 0`,
                fill: 'none',
                stroke: p.hills[4],
                'stroke-width': r1(u * 0.4),
                'stroke-linecap': 'round',
                opacity: 0.7,
            }),
        );
    }

    if (rng() < 0.45) {
        // A hot-air balloon on the side away from the sun.
        const bx = sx < w / 2 ? w * between(rng, 0.62, 0.85) : w * between(rng, 0.15, 0.38);
        const by = h * between(rng, 0.14, 0.26);
        const br = u * between(rng, 4.5, 6.5);
        s.layers.push(
            el('g', {}, [
                el('path', {
                    d: `M${r1(bx - br * 0.55)} ${r1(by + br * 1.05)} L${r1(bx - br * 0.22)} ${r1(by + br * 1.75)} M${r1(bx + br * 0.55)} ${r1(by + br * 1.05)} L${r1(bx + br * 0.22)} ${r1(by + br * 1.75)}`,
                    stroke: p.hills[3],
                    'stroke-width': r1(u * 0.3),
                }),
                el('rect', { x: r1(bx - br * 0.24), y: r1(by + br * 1.72), width: r1(br * 0.48), height: r1(br * 0.38), rx: 1, fill: p.hills[3] }),
                el('path', {
                    d: `M${r1(bx)} ${r1(by - br)} C${r1(bx + br * 1.35)} ${r1(by - br)} ${r1(bx + br * 0.9)} ${r1(by + br * 0.9)} ${r1(bx)} ${r1(by + br * 1.2)} C${r1(bx - br * 0.9)} ${r1(by + br * 0.9)} ${r1(bx - br * 1.35)} ${r1(by - br)} ${r1(bx)} ${r1(by - br)} Z`,
                    fill: p.low,
                }),
                el('path', {
                    d: `M${r1(bx)} ${r1(by - br)} C${r1(bx + br * 0.45)} ${r1(by - br * 0.6)} ${r1(bx + br * 0.4)} ${r1(by + br * 0.8)} ${r1(bx)} ${r1(by + br * 1.2)} C${r1(bx - br * 0.4)} ${r1(by + br * 0.8)} ${r1(bx - br * 0.45)} ${r1(by - br * 0.6)} ${r1(bx)} ${r1(by - br)} Z`,
                    fill: p.mid,
                    opacity: 0.85,
                }),
            ]),
        );
    }

    const bases = [0.56, 0.64, 0.73, 0.83, 0.95];
    const amps = [0.12, 0.1, 0.09, 0.08, 0.07];
    bases.forEach((base, i) => {
        s.layers.push(el('path', { d: ridge(rng, w, h, h * base, h * amps[i], 6 + i, true), fill: p.hills[i] }));
        if (i === 1 && landmark === 'lake') {
            // A still lake between the far hills, mirroring the sun.
            s.layers.push(
                el('rect', {
                    x: 0,
                    y: r1(h * 0.62),
                    width: w,
                    height: r1(h * 0.2),
                    fill: linear(s, 'lake', [
                        [0, p.low, 0.95],
                        [1, p.mid, 0.95],
                    ]),
                }),
            );
            const glints: string[] = [];
            for (let k = 0; k < 7; k++) {
                const half = sr * (1.5 - k * 0.17);
                glints.push(`M${r1(sx - half)} ${r1(h * 0.635 + k * h * 0.022)} h${r1(half * 2)} v${r1(u * 0.55)} h${r1(-half * 2)} Z`);
            }
            s.layers.push(el('path', { d: glints.join(' '), fill: p.sun, opacity: 0.55 }));
        }
        if (i === 2 && landmark === 'ruins') {
            // Arched ruins standing on the middle hills.
            const rx = w * between(rng, 0.18, 0.82);
            const base = h * 0.78;
            const span = u * 7;
            const cols: string[] = [];
            for (let k = 0; k < 4; k++) {
                const x = rx + (k - 1.5) * span;
                const height = u * (k === 3 && rng() < 0.6 ? between(rng, 6, 10) : between(rng, 15, 19));
                cols.push(`M${r1(x - u * 1.2)} ${r1(base)} V${r1(base - height)} H${r1(x + u * 1.2)} V${r1(base)} Z`);
                if (k < 3 && height > u * 12) {
                    cols.push(
                        `M${r1(x + u * 1.2)} ${r1(base - u * 15)} A${r1(span / 2 - u * 1.2)} ${r1(span / 2)} 0 0 1 ${r1(x + span - u * 1.2)} ${r1(base - u * 15)} v${r1(-u * 1.6)} H${r1(x + u * 1.2)} Z`,
                    );
                }
            }
            s.layers.push(el('path', { d: cols.join(' '), fill: p.hills[3] }));
        }
        if (i === 3 && landmark === 'river') {
            const xb = w * between(rng, 0.35, 0.65);
            const wb = w * 0.09;
            const yt = h * 0.68;
            const c1x = xb + w * between(rng, -0.25, 0.25);
            const c2x = sx + w * between(rng, -0.2, 0.2);
            s.layers.push(
                el('path', {
                    d: `M${r1(xb - wb)} ${h} C${r1(c1x - wb * 0.6)} ${r1(h * 0.88)} ${r1(c2x - wb * 0.15)} ${r1(h * 0.76)} ${r1(sx)} ${r1(yt)} C${r1(c2x + wb * 0.15)} ${r1(h * 0.76)} ${r1(c1x + wb * 0.6)} ${r1(h * 0.88)} ${r1(xb + wb)} ${h} Z`,
                    fill: linear(s, 'river', [
                        [0, p.river, 0.15],
                        [1, p.river, 0.75],
                    ]),
                }),
            );
        }
    });

    return p.sun;
}

// --- Action: an energy burst with blades converging on a white-hot core -----------------------

const ACTION = [
    { bgA: '#16040a', bgB: '#5c0d18', core: '#ffe08a', ray: '#ff6b3d', shards: ['#ff3d4f', '#ff8a3d', '#ffd166'] },
    { bgA: '#070418', bgB: '#2e1065', core: '#e0f7ff', ray: '#22d3ee', shards: ['#8b5cf6', '#22d3ee', '#e879f9'] },
    { bgA: '#120902', bgB: '#5a2a0a', core: '#fff6d5', ray: '#fbbf24', shards: ['#f59e0b', '#ef4444', '#fde68a'] },
] as const;

function action(s: Scene): string {
    const p = pick(s.rng, ACTION);
    const { w, h, u, rng } = s;
    const fx = w * between(rng, 0.35, 0.65);
    const fy = h * between(rng, 0.28, 0.42);
    const far = Math.hypot(w, h);

    fillCanvas(
        s,
        radial(
            s,
            'bg',
            [
                [0, p.bgB],
                [1, p.bgA],
            ],
            { cx: fx, cy: fy, r: far * 0.75 },
        ),
    );

    const rays: string[] = [];
    for (let k = 0; k < 22; k++) {
        const a = (k / 22) * Math.PI * 2 + between(rng, -0.08, 0.08);
        const spread = between(rng, 0.015, 0.05);
        rays.push(
            `M${r1(fx)} ${r1(fy)} L${r1(fx + Math.cos(a - spread) * far)} ${r1(fy + Math.sin(a - spread) * far)} L${r1(fx + Math.cos(a + spread) * far)} ${r1(fy + Math.sin(a + spread) * far)} Z`,
        );
    }
    s.layers.push(el('path', { d: rays.join(' '), fill: p.ray, opacity: 0.13 }));

    for (let k = 0; k < 6; k++) {
        const a = between(rng, 0, Math.PI * 2);
        const dist = far * between(rng, 0.16, 0.34);
        const len = far * between(rng, 0.25, 0.5);
        const width = u * between(rng, 2.5, 6);
        const ax = fx + Math.cos(a) * dist;
        const ay = fy + Math.sin(a) * dist;
        const bx = fx + Math.cos(a) * (dist + len);
        const by = fy + Math.sin(a) * (dist + len);
        const nx = -Math.sin(a) * width;
        const ny = Math.cos(a) * width;
        const color = pick(rng, p.shards);
        s.layers.push(
            el('path', {
                d: `M${r1(ax)} ${r1(ay)} L${r1(bx + nx)} ${r1(by + ny)} L${r1(bx - nx * 0.4)} ${r1(by - ny * 0.4)} Z`,
                fill: linear(
                    s,
                    `shard${k}`,
                    [
                        [0, color, 0.95],
                        [1, color, 0.05],
                    ],
                    { x1: r1(ax), y1: r1(ay), x2: r1(bx), y2: r1(by) },
                    true,
                ),
            }),
        );
    }

    const coreR = Math.min(w, h) * 0.42;
    s.layers.push(
        el('circle', {
            cx: r1(fx),
            cy: r1(fy),
            r: r1(coreR),
            fill: radial(
                s,
                'core',
                [
                    [0, p.core, 0.95],
                    [0.18, p.core, 0.6],
                    [0.45, p.ray, 0.18],
                    [1, p.ray, 0],
                ],
                { cx: fx, cy: fy, r: coreR },
            ),
        }),
    );

    for (let k = 0; k < 2; k++) {
        const y = h * between(rng, 0.2, 0.7);
        const tilt = h * between(rng, 0.25, 0.45) * (rng() < 0.5 ? -1 : 1);
        const th = u * between(rng, 0.6, 1.4);
        s.layers.push(
            el('path', {
                d: `M${r1(-w * 0.05)} ${r1(y + tilt)} L${r1(w * 1.05)} ${r1(y - tilt)} L${r1(w * 1.05)} ${r1(y - tilt + th)} L${r1(-w * 0.05)} ${r1(y + tilt + th * 2.5)} Z`,
                fill: p.core,
                opacity: 0.55,
            }),
        );
    }

    const sparks: string[] = [];
    for (let k = 0; k < 40; k++) {
        const a = between(rng, 0, Math.PI * 2);
        const d = far * Math.pow(rng(), 0.7) * 0.5;
        const size = u * between(rng, 0.3, 0.9);
        const px = fx + Math.cos(a) * d;
        const py = fy + Math.sin(a) * d;
        sparks.push(`M${r1(px)} ${r1(py - size)} L${r1(px + size)} ${r1(py)} L${r1(px)} ${r1(py + size)} L${r1(px - size)} ${r1(py)} Z`);
    }
    s.layers.push(el('path', { d: sparks.join(' '), fill: p.core, opacity: 0.8 }));

    return p.shards[0];
}

// --- Indie: a ringed planet, soft colour fields, pixel clouds and sparkles -------------------

const INDIE = [
    { bgA: '#0f1f2e', bgB: '#1d4a52', blobs: ['#7ee0c3', '#ffb4a2', '#c3b1ff'], planet: '#ffd6a5', ring: '#fff3d6', cloud: '#e8fff8' },
    { bgA: '#191233', bgB: '#3d2b6b', blobs: ['#c3b1ff', '#fde68a', '#93c5fd'], planet: '#f9a8d4', ring: '#fde68a', cloud: '#f5f3ff' },
    { bgA: '#0f2039', bgB: '#244a7a', blobs: ['#ff9e9e', '#8ee3ff', '#ffe08a'], planet: '#a7f3d0', ring: '#e0f2fe', cloud: '#f0f9ff' },
] as const;

function indie(s: Scene): string {
    const p = pick(s.rng, INDIE);
    const { w, h, u, rng } = s;
    const m = Math.min(w, h);

    fillCanvas(
        s,
        linear(
            s,
            'bg',
            [
                [0, p.bgA],
                [1, p.bgB],
            ],
            { x1: 0, y1: 0, x2: 1, y2: 1 },
        ),
    );
    for (let k = 0; k < 3; k++) {
        s.layers.push(
            el('circle', {
                cx: r1(w * between(rng, -0.05, 1.05)),
                cy: r1(h * between(rng, 0.05, 0.95)),
                r: r1(m * between(rng, 0.18, 0.34)),
                fill: p.blobs[k],
                opacity: r1(between(rng, 0.35, 0.6)),
            }),
        );
    }

    const px = w * between(rng, 0.3, 0.7);
    const py = h * between(rng, 0.24, 0.4);
    const pr = m * between(rng, 0.12, 0.17);
    const tilt = r1(between(rng, -28, -12));
    const rx = pr * 1.9;
    const ry = pr * 0.48;
    const rotate = `rotate(${tilt} ${r1(px)} ${r1(py)})`;
    s.layers.push(
        el('ellipse', {
            cx: r1(px),
            cy: r1(py),
            rx: r1(rx),
            ry: r1(ry),
            fill: 'none',
            stroke: p.ring,
            'stroke-width': r1(u * 1.1),
            opacity: 0.45,
            transform: rotate,
        }),
    );
    s.layers.push(
        el('circle', {
            cx: r1(px),
            cy: r1(py),
            r: r1(pr),
            fill: radial(
                s,
                'planet',
                [
                    [0, '#ffffff', 0.9],
                    [0.35, p.planet],
                    [1, mix(p.planet, p.bgA, 0.55)],
                ],
                { cx: px - pr * 0.35, cy: py - pr * 0.35, r: pr * 1.5 },
            ),
        }),
    );
    s.layers.push(
        el('path', {
            d: `M${r1(px - rx)} ${r1(py)} A${r1(rx)} ${r1(ry)} 0 0 0 ${r1(px + rx)} ${r1(py)}`,
            fill: 'none',
            stroke: p.ring,
            'stroke-width': r1(u * 1.1),
            'stroke-linecap': 'round',
            transform: rotate,
        }),
    );

    const sparkles: string[] = [];
    for (let k = 0; k < 9; k++) {
        const x = w * between(rng, 0.05, 0.95);
        const y = h * between(rng, 0.05, 0.6);
        const r = u * between(rng, 0.8, 2.2);
        const q = r * 0.22;
        sparkles.push(
            `M${r1(x)} ${r1(y - r)} Q${r1(x + q)} ${r1(y - q)} ${r1(x + r)} ${r1(y)} Q${r1(x + q)} ${r1(y + q)} ${r1(x)} ${r1(y + r)} Q${r1(x - q)} ${r1(y + q)} ${r1(x - r)} ${r1(y)} Q${r1(x - q)} ${r1(y - q)} ${r1(x)} ${r1(y - r)} Z`,
        );
    }
    s.layers.push(el('path', { d: sparkles.join(' '), fill: '#ffffff', opacity: 0.85 }));

    const shape = ['..XXX...', '.XXXXXX.', 'XXXXXXXX'];
    for (let k = 0; k < 2; k++) {
        const size = u * between(rng, 1.4, 2.2);
        const ox = w * between(rng, 0.05, 0.65);
        const oy = h * between(rng, 0.48, 0.7);
        const d = shape
            .flatMap((row, y) =>
                [...row].map((c, x) => (c === 'X' ? `M${r1(ox + x * size)} ${r1(oy + y * size)} h${r1(size)} v${r1(size)} h${r1(-size)} Z` : '')),
            )
            .join(' ');
        s.layers.push(el('path', { d, fill: p.cloud, opacity: 0.9 }));
    }
    s.layers.push(el('path', { d: ridge(rng, w, h, h * 0.92, h * 0.08, 4, true), fill: mix(p.bgA, '#000000', 0.3) }));

    return p.blobs[1];
}

// --- Fallback for unknown genres: the brand gradient with concentric rings --------------------

function abstract(s: Scene): string {
    const { w, h, u, rng } = s;
    const [a, b, c] = pick(rng, [
        ['#4c1d95', '#0e7490', '#c4b5fd'],
        ['#581c87', '#be185d', '#f5d0fe'],
        ['#1e3a8a', '#6d28d9', '#bfdbfe'],
    ] as const);
    fillCanvas(
        s,
        linear(
            s,
            'bg',
            [
                [0, a],
                [1, b],
            ],
            { x1: 0, y1: 0, x2: 1, y2: 1 },
        ),
    );
    const cx = w * between(rng, 0.55, 0.85);
    const cy = h * between(rng, 0.15, 0.35);
    s.layers.push(
        el(
            'g',
            { fill: 'none', stroke: c, 'stroke-opacity': 0.25, 'stroke-width': r1(u * 0.4) },
            Array.from({ length: 7 }, (_, i) => el('circle', { cx: r1(cx), cy: r1(cy), r: r1(Math.min(w, h) * (0.08 + i * 0.07)) })),
        ),
    );

    return c;
}

// --- Composition -------------------------------------------------------------------------------

const SCENES: Record<string, (s: Scene) => string> = { racing, rpg, shooter, strategy, adventure, action, indie };

function titleLines(name: string, maxChars: number): string[] {
    const lines: string[] = [];
    for (const word of name.toUpperCase().split(/\s+/).filter(Boolean)) {
        const last = lines[lines.length - 1];
        if (last !== undefined && `${last} ${word}`.length <= maxChars) {
            lines[lines.length - 1] = `${last} ${word}`;
        } else {
            lines.push(word);
        }
    }

    return lines.slice(0, 3);
}

function paintTitle(s: Scene, name: string, accent: string): void {
    const { w, h } = s;
    const margin = w * 0.07;
    const lines = titleLines(name, 11);
    const longest = Math.max(...lines.map((line) => line.length));
    const size = Math.min(w * 0.14, (w - margin * 2) / (longest * 0.72));
    const lead = size * 0.98;
    const firstY = h - margin * 1.05 - (lines.length - 1) * lead;

    fillCanvas(
        s,
        linear(s, 'fade', [
            [0.45, '#06070c', 0],
            [1, '#06070c', 0.9],
        ]),
    );
    s.layers.push(
        el('rect', { x: r1(margin), y: r1(firstY - size * 1.08), width: r1(size * 0.9), height: r1(Math.max(3, size * 0.07)), rx: 2, fill: accent }),
    );
    lines.forEach((line, i) => {
        s.layers.push({
            tag: 'text',
            attrs: {
                x: r1(margin),
                y: r1(firstY + i * lead),
                fill: '#ffffff',
                'font-family': 'Sora, Instrument Sans, sans-serif',
                'font-weight': 800,
                'font-size': r1(size),
                'letter-spacing': r1(-size * 0.02),
                'paint-order': 'stroke',
                stroke: '#000000',
                'stroke-opacity': 0.25,
                'stroke-width': r1(size * 0.05),
            },
            text: line,
        });
    });
}

export function coverArt(options: CoverOptions): CoverArt {
    const [w, h] = SIZES[options.format];
    const s: Scene = {
        rng: mulberry32(hash(options.slug)),
        w,
        h,
        u: Math.min(w, h) / 100,
        id: (name) => `${options.idPrefix}-${name}`,
        defs: [],
        layers: [],
    };
    const accent = (SCENES[(options.genre ?? '').toLowerCase()] ?? abstract)(s);

    fillCanvas(
        s,
        radial(
            s,
            'vignette',
            [
                [0.55, '#000000', 0],
                [1, '#000000', 0.5],
            ],
            { cx: w / 2, cy: h / 2, r: Math.hypot(w, h) / 2 },
        ),
    );
    if (options.title && options.format === 'portrait') {
        paintTitle(s, options.name, accent);
    }

    return { width: w, height: h, defs: s.defs, layers: s.layers };
}
