import { isolatedCompose } from './docker-fixture';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

export function activationLink(): string {
 const projectRoot = fileURLToPath(new URL('../../..', import.meta.url));
 const guard = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(!app()->environment('testing')||config('database.default')!=='sqlite'||DB::connection()->getDatabaseName()!=='/var/www/html/storage/e2e.sqlite')throw new RuntimeException('Isolated E2E database required.');`;
 return execFileSync('docker', [...isolatedCompose(), 'exec', '-T', 'app', 'php', '-r', guard + String.raw`Illuminate\Support\Facades\Mail::fake();$job=Illuminate\Support\Facades\Queue::connection('database')->pop('account-activation');$job->fire();$job->delete();echo Illuminate\Support\Facades\Mail::sent(App\Mail\AccountActivationMail::class)->sole()->activationUrl;`], { cwd: projectRoot, env: { ...process.env, DB_PASSWORD: 'ci-e2e-password' }, encoding: 'utf8' }).trim();
}
