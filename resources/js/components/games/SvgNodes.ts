import type { SvgNode } from '@/lib/cover-art';
import { defineComponent, h, type PropType, type VNode } from 'vue';

function render(node: SvgNode): VNode {
    return h(node.tag, node.attrs, node.text ?? node.children?.map(render));
}

/** Renders SVG element descriptions (see lib/cover-art.ts) as real elements, without v-html. */
export default defineComponent({
    name: 'SvgNodes',
    props: { nodes: { type: Array as PropType<SvgNode[]>, required: true } },
    setup: (props) => () => props.nodes.map(render),
});
