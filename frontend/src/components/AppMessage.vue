<script lang="ts">
import { Comment, Fragment, Text, defineComponent, h, type PropType, type VNode } from 'vue';
import { NAlert, type AlertProps } from 'naive-ui';
import { useFeedback } from '../lib/feedback';

function plainText(nodes: VNode[]): string | null {
 let result = '';
 for (const node of nodes) {
  if (node.type === Comment) continue;
  if (node.type === Text) result += String(node.children ?? '');
  else if (node.type === Fragment && Array.isArray(node.children)) {
   const nested = plainText(node.children as VNode[]);
   if (nested === null) return null;
   result += nested;
  } else return null;
 }
 return result.trim();
}

export default defineComponent({
 inheritAttrs: false,
 props: { type: { type: String as PropType<AlertProps['type']>, default: 'default' } },
 setup(props, { attrs, slots }) {
  const feedback = useFeedback();
  let previous = '';
  return () => {
   const nodes = slots.default?.() ?? [];
   const message = plainText(nodes);
   if ((props.type === 'success' || props.type === 'error') && message !== null && !attrs.id && !slots.header) {
    const signature = `${props.type}:${message}`;
    if (signature !== previous) { previous = signature; feedback.show(props.type, message); }
    return null;
   }
   previous = '';
   return h(NAlert, { ...attrs, type: props.type }, { ...slots, default: () => nodes });
  };
 },
});
</script>
