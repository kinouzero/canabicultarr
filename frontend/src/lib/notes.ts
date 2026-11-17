import DOMPurify from 'dompurify';
import { Marked } from 'marked';
import TurndownService from 'turndown';

const escape = (text: string) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const markdown = new Marked({ gfm: true, breaks: true, renderer: {
 html: token => escape(token.text),
 image: token => escape(token.text),
} });
const allowed = {
 ALLOWED_TAGS: ['p', 'br', 'strong', 'b', 'em', 'i', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'a', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'del', 's', 'input'],
 ALLOWED_ATTR: ['href', 'title', 'start', 'type', 'checked', 'disabled'],
 ALLOW_DATA_ATTR: false,
};
const converter = new TurndownService({ headingStyle: 'atx', bulletListMarker: '-', codeBlockStyle: 'fenced', strongDelimiter: '**', emDelimiter: '_' });

export function renderNotes(value: string): string {
 return DOMPurify.sanitize(markdown.parse(value, { async: false }), allowed);
}
export function notesMarkdown(html: string): string {
 const content = DOMPurify.sanitize(html, { ...allowed, ALLOWED_TAGS: [...allowed.ALLOWED_TAGS, 'div'], ALLOWED_ATTR: [...allowed.ALLOWED_ATTR, 'class', 'data-list'], RETURN_DOM: true }) as HTMLElement;
 // Quill 2 represents bullet and numbered items inside the same ordered list.
 content.querySelectorAll('ol').forEach(list => {
  if (!list.querySelector(':scope > li[data-list]')) return;
  const fragment = document.createDocumentFragment(); let group: HTMLElement | null = null;
  for (const item of Array.from(list.children)) {
   const tag = item.getAttribute('data-list') === 'bullet' ? 'ul' : 'ol';
   if (!group || group.tagName.toLowerCase() !== tag) { group = document.createElement(tag); fragment.append(group); }
   item.removeAttribute('data-list'); group.append(item);
  }
  list.replaceWith(fragment);
 });
 content.querySelectorAll('.ql-code-block-container').forEach(block => {
  const pre = document.createElement('pre'); const code = document.createElement('code');
  code.textContent = Array.from(block.children).map(line => line.textContent).join('\n');
  pre.append(code); block.replaceWith(pre);
 });
 // Quill exports code blocks as bare pre elements; Turndown expects pre/code.
 content.querySelectorAll('pre').forEach(pre => {
  if (pre.querySelector('code')) return;
  const code = document.createElement('code'); code.textContent = pre.textContent;
  pre.replaceChildren(code);
 });
 return converter.turndown(content).replace(/\u00a0/g, ' ');
}
// Keep advanced Markdown in source mode so a visual edit cannot discard it.
export function supportsVisualNotes(value: string): boolean {
 let supported = true;
 markdown.walkTokens(markdown.lexer(value), token => {
  if (token.type === 'html' || token.type === 'table' || token.type === 'image' || token.type === 'hr' || token.type === 'del' || (token.type === 'list_item' && token.task) || (token.type === 'code' && token.lang)) supported = false;
 });
 return supported;
}
