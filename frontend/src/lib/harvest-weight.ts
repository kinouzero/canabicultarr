export function validHarvestWeight(value: string | number): boolean {
 return /^\d{1,10}(?:\.\d{1,2})?$/.test(String(value)) && Number(value) > 0;
}
