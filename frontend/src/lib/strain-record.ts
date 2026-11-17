import type { InjectionKey } from 'vue';

export const strainRecordOpener: InjectionKey<(id: string, trigger: HTMLElement) => void> = Symbol('strain-record-opener');
