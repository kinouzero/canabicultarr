/** Only the harness-generated configuration may access the disposable acceptance stack. */
export function isolatedCompose(): string[] {
 const file = process.env.E2E_COMPOSE_FILE;
 if (!file) throw new Error('Run this scenario through scripts/test-auth-e2e.sh.');
 return ['compose', '-p', 'canabicultarr-e2e', '-f', file];
}
