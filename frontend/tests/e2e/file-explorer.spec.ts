import { isolatedCompose } from './docker-fixture';
import { revealTabbedControl } from './navigation';
import { test, expect } from '../coverage/fixture';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { openModule } from './navigation';

test('file explorer creates nested folders, uploads into the current folder, moves files and protects nonempty folders', async ({ page }) => {
 const projectRoot = fileURLToPath(new URL('../../..', import.meta.url));
 const prefix = [...isolatedCompose(), 'exec', '-T', 'app', 'php', '-r'];
 const guard = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(!app()->environment('testing')||config('database.default')!=='sqlite'||DB::connection()->getDatabaseName()!=='/var/www/html/storage/e2e.sqlite')throw new RuntimeException('Isolated E2E database required.');`;
 const php = (code: string) => execFileSync('docker', [...prefix, guard + code], { cwd: projectRoot, env: { ...process.env, DB_PASSWORD: 'ci-e2e-password' }, stdio: 'pipe' });
 const errors: string[] = []; page.on('pageerror', error => errors.push(error.message));
 try {
  php(String.raw`App\Models\User::factory()->create(['name'=>'Explorer Administrator','email'=>'explorer@example.test','password'=>'Fictional file explorer password 123!','permission_role_ids'=>[Database\Seeders\AccessProfileSeeder::defaultProfile('administrator')->id]]);`);
  await page.addInitScript(() => localStorage.setItem('locale', 'fr')); await page.goto('/');
  await (await revealTabbedControl(page.locator('#login-email'))).fill('explorer@example.test'); await (await revealTabbedControl(page.locator('#login-password'))).fill('Fictional file explorer password 123!'); await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
  await openModule(page, 'documents');
  const createFolder = async (name: string) => {
   await page.getByRole('button', { name: 'Nouveau dossier', exact: true }).click(); await (await revealTabbedControl(page.locator('#folder-name'))).fill(name);
   await page.getByRole('button', { name: /^(?:Enregistrer le dossier|Créer)$/, exact: true }).click();
   await expect(page.getByRole('navigation', { name: 'Chemin du dossier' }).getByRole('button', { name, exact: true })).toHaveAttribute('aria-current', 'page');
  };
  await createFolder('E2E Manuals'); await createFolder('E2E Pumps');
  await page.getByRole('button', { name: 'Ajouter un document', exact: true }).click();
  await expect(page.locator('#document-folder')).not.toHaveValue('');
  await (await revealTabbedControl(page.getByLabel('Stockage', { exact: true }).and(page.locator('select')))).selectOption('documents');
  const zone = page.getByTestId('upload-selection');
  await zone.locator('input[type=file]').setInputFiles({ name: 'explorer-note.txt', mimeType: 'text/plain', buffer: Buffer.from('Fictional pump instructions') });
  const selection = page.getByTestId('upload-selection');
  await selection.getByRole('button', { name: 'Aperçu de explorer-note.txt', exact: true }).click();
  const localPreview = page.getByRole('dialog', { name: 'explorer-note.txt', exact: true });
  await expect(localPreview.locator('pre')).toHaveText('Fictional pump instructions');
  await localPreview.getByRole('button', { name: 'Fermer l’aperçu', exact: true }).click();
  await expect(selection.getByText('Prêt à envoyer', { exact: true })).toBeVisible();
  await selection.getByRole('button', { name: 'Retirer explorer-note.txt', exact: true }).click();
  await expect(selection.locator('li')).toHaveCount(0);
  await page.locator('#document-file').setInputFiles({ name: 'explorer-note.txt', mimeType: 'text/plain', buffer: Buffer.from('Fictional pump instructions') });
  await (await revealTabbedControl(page.locator('#document-title'))).fill('E2E Pump Manual'); await page.getByRole('button', { name: /^(?:Enregistrer le document|Importer)$/, exact: true }).click();
  await expect(page.getByRole('button', { name: 'Visionner explorer-note.txt', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Déplacer E2E Pump Manual', exact: true }).click();
  await (await revealTabbedControl(page.locator('#file-destination'))).selectOption({ label: 'E2E Manuals' }); await page.getByRole('button', { name: 'Déplacer', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Visionner explorer-note.txt', exact: true })).toHaveCount(0);
  const breadcrumb = page.getByRole('navigation', { name: 'Chemin du dossier' });
  await breadcrumb.getByRole('button', { name: 'E2E Manuals', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Visionner explorer-note.txt', exact: true })).toBeVisible();
  await breadcrumb.getByRole('button', { name: 'Supprimer le dossier', exact: true }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Supprimer le dossier', exact: true }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'contient encore' }).first()).toContainText('contient encore'); await page.getByRole('dialog').getByRole('button', { name: 'Annuler', exact: true }).click();
  await breadcrumb.getByRole('button', { name: 'Modifier le dossier', exact: true }).click();
  await expect(page.locator('#folder-parent option')).not.toContainText('E2E Pumps');
  await (await revealTabbedControl(page.locator('#folder-name'))).fill('E2E Guides'); await page.getByRole('button', { name: /^(?:Enregistrer le dossier|Créer)$/, exact: true }).click();
  await expect(breadcrumb.getByRole('button', { name: 'E2E Guides', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'E2E Pumps', exact: true }).click();
  await breadcrumb.getByRole('button', { name: 'Supprimer le dossier', exact: true }).click(); await page.getByRole('dialog').getByRole('button', { name: 'Supprimer le dossier', exact: true }).click();
  await expect(page.getByText('Dossier supprimé.', { exact: true })).toBeVisible();
  await page.getByRole('tree').getByText('Tous les fichiers', { exact: true }).click();
  await expect(page.getByRole('button', { name: 'Visionner explorer-note.txt', exact: true })).toBeVisible();
  if (process.env.PLAYWRIGHT_EXPLORER_REVIEW) await page.screenshot({ path: test.info().outputPath('canabicultarr-explorer-desktop.png'), fullPage: true, animations: 'disabled' });
  await page.getByRole('button', { name: 'Visionner explorer-note.txt', exact: true }).click();
  await expect(page.getByRole('dialog').locator('pre')).toHaveText('Fictional pump instructions'); await page.keyboard.press('Escape');
  await page.setViewportSize({ width: 390, height: 844 });
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  if (process.env.PLAYWRIGHT_EXPLORER_REVIEW) await page.screenshot({ path: test.info().outputPath('canabicultarr-explorer-mobile.png'), fullPage: true, animations: 'disabled' });
  expect(errors).toEqual([]);
 } finally {
  php(String.raw`DB::transaction(function(){$user=App\Models\User::where('email','explorer@example.test')->first();if(!$user)return;$files=App\Models\Document::withoutGlobalScopes()->where('uploaded_by',$user->id)->get();foreach($files as $file){Storage::disk($file->disk)->delete($file->path);$file->delete();}App\Models\DocumentFolder::where('created_by',$user->id)->whereNotNull('parent_id')->delete();App\Models\DocumentFolder::where('created_by',$user->id)->delete();DB::table('sessions')->where('user_id',$user->id)->delete();$user->delete();});`);
 }
});
