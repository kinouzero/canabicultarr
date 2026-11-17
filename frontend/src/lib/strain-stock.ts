import type { Strain } from '../api/strains';

export type PlantStrainChoice = Pick<Strain, 'id' | 'name'> & Partial<Pick<Strain, 'seedStock'>>;

export function seedStockEmpty(strain: { seedStock?: Strain['seedStock'] } | null | undefined): boolean {
 return !!strain?.seedStock && Number(strain.seedStock.quantity) <= 0;
}
