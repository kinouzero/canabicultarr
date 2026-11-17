import { mergeBrowserCoverage } from '../tests/coverage/merge.ts';

await mergeBrowserCoverage(process.argv.slice(2));
