import type { ReferenceOption } from '../api/sensors';
import type { Page } from '../api/types';

export interface BreadcrumbOption extends ReferenceOption { icon: string }
export type BreadcrumbPageLoader = (search: string, page: number) => Promise<Page<BreadcrumbOption>>;
