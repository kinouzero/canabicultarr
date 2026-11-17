export const geneticsKinds = ['sativa', 'indica', 'hybrid'] as const;
export type GeneticsKind = typeof geneticsKinds[number];
export type GeneticsColors = Record<GeneticsKind, string>;
export const defaultGeneticsColors: GeneticsColors = { sativa: '#c75b50', indica: '#527eae', hybrid: '#b9922e' };
export function readGeneticsColors(value: unknown): GeneticsColors {
 if (value && typeof value === 'object' && geneticsKinds.every(key => /^#[0-9a-f]{6}$/i.test((value as Record<string, string>)[key] ?? ''))) return { ...(value as GeneticsColors) };
 return { ...defaultGeneticsColors };
}
export function geneticsColorStyle(color: string) {
 const rgb = [1, 3, 5].map(offset => parseInt(color.slice(offset, offset + 2), 16));
 const mix = (white: number) => `rgb(${rgb.map(channel => Math.round(channel * (1 - white) + 255 * white)).join(', ')})`;
 return { '--genetics-color': color, backgroundColor: `var(--genetics-background, ${mix(.88)})`, borderColor: `var(--genetics-border, ${mix(.65)})`, color: `var(--genetics-foreground, rgb(${rgb.map(channel => Math.round(channel * .42)).join(', ')}))` };
}
