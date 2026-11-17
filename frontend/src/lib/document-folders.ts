import type { DocumentFolder } from '../api/documents';

export function folderAncestors(id: string | null, byId: ReadonlyMap<string, DocumentFolder>): DocumentFolder[] {
 const result: DocumentFolder[] = []; const seen = new Set<string>();
 while (id && byId.has(id) && !seen.has(id)) {
  seen.add(id); const folder = byId.get(id)!; result.unshift(folder); id = folder.parentId;
 }
 return result;
}
