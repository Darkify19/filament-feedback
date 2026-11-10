<?php

/**
 * A throwaway host app for the plugin: Testbench's Laravel skeleton + the test fixture
 * panel (/testing, plugin on, button + inbox), a file SQLite DB and two seeded users.
 *
 *   php .claude/skills/run-filament-feedback/server.php setup     # fresh DB + seed + assets
 *   php -S 127.0.0.1:8123 -t vendor/orchestra/testbench-core/laravel/public \
 *       .claude/skills/run-filament-feedback/server.php            # serve
 *
 * Log in by visiting /_as/1 (Ana, staff) or /_as/2 (Ben, triager).
 */

use CarlJanzell\FilamentFeedback\Tests\Fixtures\Models\User;
use CarlJanzell\FilamentFeedback\Tests\Fixtures\TestPanelProvider;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Foundation\Application;

$root = dirname(__DIR__, 3);
$public = $root.'/vendor/orchestra/testbench-core/laravel/public';

// php -S: let the built-in server hand out published assets (css/js/fonts) itself.
if (PHP_SAPI === 'cli-server' && is_file($public.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require $root.'/vendor/autoload.php';

// The fixture panel has no middleware (tests use actingAs); a browser needs the usual stack.
class RunPanelProvider extends TestPanelProvider
{
    public function panel(Filament\Panel $panel): Filament\Panel
    {
        return parent::panel($panel)
            ->pages([Filament\Pages\Dashboard::class]) // staff can't open the inbox; without a page they loop on /testing
            ->middleware([
                Illuminate\Cookie\Middleware\EncryptCookies::class,
                Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
                Illuminate\Session\Middleware\StartSession::class,
                Filament\Http\Middleware\AuthenticateSession::class,
                Illuminate\View\Middleware\ShareErrorsFromSession::class,
                Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
                Illuminate\Routing\Middleware\SubstituteBindings::class,
                Filament\Http\Middleware\DisableBladeIconComponents::class,
                Filament\Http\Middleware\DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Filament\Http\Middleware\Authenticate::class]);
    }
}

$db = getenv('FFB_DB') ?: sys_get_temp_dir().'/filament-feedback-run.sqlite';

$app = Application::create(
    resolvingCallback: fn ($app) => $app->booting(function ($app) use ($db): void {
        // booting(): config is loaded by now, and nothing has read these keys yet.
        $app["config"]->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.debug' => true,
            'app.url' => getenv('APP_URL') ?: 'http://127.0.0.1:8123',
            'database.default' => 'run',
            'database.connections.run' => ['driver' => 'sqlite', 'database' => $db, 'prefix' => '', 'foreign_key_constraints' => true],
            'session.driver' => 'file',
            'auth.providers.users.model' => User::class,
        ]);
    }),
    // Same order as tests/TestCase.php (Livewire after Filament on purpose).
    options: ['extra' => ['dont-discover' => ['*'], 'providers' => array_values(array_filter([
        Filament\Actions\ActionsServiceProvider::class,
        RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider::class,
        BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
        BladeUI\Icons\BladeIconsServiceProvider::class,
        Filament\FilamentServiceProvider::class,
        Filament\Forms\FormsServiceProvider::class,
        Filament\Infolists\InfolistsServiceProvider::class,
        Filament\Notifications\NotificationsServiceProvider::class,
        Filament\Schemas\SchemasServiceProvider::class,
        Filament\Support\SupportServiceProvider::class,
        Filament\Tables\TablesServiceProvider::class,
        Filament\Widgets\WidgetsServiceProvider::class,
        Livewire\LivewireServiceProvider::class,
        CarlJanzell\FilamentFeedback\FeedbackServiceProvider::class,
        RunPanelProvider::class,
    ], 'class_exists'))]],
);

Route::middleware('web')->get('/_as/{id}', function (string $id) {
    Auth::loginUsingId((int) $id);

    return redirect(request('to', '/testing'));
});

if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? null) !== 'setup') {
        exit("usage: php server.php setup\n");
    }

    @unlink($db);
    touch($db);

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->string('password');
        $table->boolean('is_admin')->default(false);
        $table->rememberToken();
        $table->timestamps();
    });
    Schema::create('notifications', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->morphs('notifiable');
        $table->text('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });

    $console = $app->make(ConsoleKernel::class);
    $console->call('migrate', ['--force' => true]);
    $console->call('filament:assets');
    echo $console->output();

    User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'x']);
    User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'x', 'is_admin' => true]);
    echo "DB ready at {$db} (1 = Ana, staff; 2 = Ben, triager)\n";

    return;
}

$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request = Request::capture());
$response->send();
$kernel->terminate($request, $response);
