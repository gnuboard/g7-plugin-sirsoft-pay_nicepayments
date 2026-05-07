<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class PluginTestCase extends TestCase
{
    use RefreshDatabase;

    protected function shouldSeed(): bool
    {
        return true;
    }

    protected function seeder(): string
    {
        return \Modules\Sirsoft\Ecommerce\Database\Seeders\TestingSeeder::class;
    }

    protected function migrateFreshUsing(): array
    {
        return [
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--seed' => $this->shouldSeed(),
            '--seeder' => $this->seeder(),
            '--path' => [
                'database/migrations',
                'modules/sirsoft-ecommerce/database/migrations',
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerModuleAutoload();
        $this->registerPluginAutoload();

        $this->app->register(\Modules\Sirsoft\Ecommerce\Providers\EcommerceServiceProvider::class);

        $this->registerModuleRoutes();
        $this->registerPluginRoutes();
    }

    protected function registerModuleAutoload(): void
    {
        $moduleBasePath = base_path('modules/sirsoft-ecommerce/src/');

        spl_autoload_register(function ($class) use ($moduleBasePath) {
            $prefix = 'Modules\\Sirsoft\\Ecommerce\\';
            $len = strlen($prefix);

            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relativeClass = substr($class, $len);
            $file = $moduleBasePath . str_replace('\\', '/', $relativeClass) . '.php';

            if (file_exists($file)) {
                require $file;
            }
        });

        $helpersFile = $moduleBasePath . 'Helpers/helpers.php';
        if (file_exists($helpersFile)) {
            require_once $helpersFile;
        }
    }

    protected function registerPluginAutoload(): void
    {
        $pluginBasePath = base_path('plugins/sirsoft-pay_nicepayments/src/');

        spl_autoload_register(function ($class) use ($pluginBasePath) {
            $prefix = 'Plugins\\Sirsoft\\Nicepayments\\';
            $len = strlen($prefix);

            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relativeClass = substr($class, $len);
            $file = $pluginBasePath . str_replace('\\', '/', $relativeClass) . '.php';

            if (file_exists($file)) {
                require $file;
            }
        });
    }

    protected function registerModuleRoutes(): void
    {
        $apiRoutesFile = base_path('modules/sirsoft-ecommerce/src/routes/api.php');

        if (file_exists($apiRoutesFile)) {
            \Illuminate\Support\Facades\Route::prefix('api/modules/sirsoft-ecommerce')
                ->name('api.modules.sirsoft-ecommerce.')
                ->middleware('api')
                ->group($apiRoutesFile);
        }
    }

    protected function registerPluginRoutes(): void
    {
        $webRoutesFile = base_path('plugins/sirsoft-pay_nicepayments/src/routes/web.php');

        if (file_exists($webRoutesFile)) {
            \Illuminate\Support\Facades\Route::prefix('plugins/sirsoft-pay_nicepayments')
                ->name('plugins.sirsoft-pay_nicepayments.')
                ->middleware('web')
                ->group($webRoutesFile);
        }
    }

    protected function createAdminUser(array $permissions = []): \App\Models\User
    {
        $user = \App\Models\User::factory()->create();

        $uniqueRoleIdentifier = 'admin-test-' . $user->id . '-' . time();
        $userRole = \App\Models\Role::create([
            'identifier' => $uniqueRoleIdentifier,
            'name' => ['ko' => '테스트 관리자', 'en' => 'Test Admin'],
        ]);
        $user->roles()->attach($userRole->id);

        $adminAccessPermission = \App\Models\Permission::firstOrCreate(
            ['identifier' => 'admin.access'],
            [
                'name' => ['ko' => '관리자 접근', 'en' => 'Admin Access'],
                'type' => \App\Enums\PermissionType::Admin,
            ]
        );
        $userRole->permissions()->attach($adminAccessPermission->id);

        if (! empty($permissions)) {
            foreach ($permissions as $permissionIdentifier) {
                $permission = \App\Models\Permission::firstOrCreate(
                    ['identifier' => $permissionIdentifier],
                    [
                        'name' => ['ko' => $permissionIdentifier, 'en' => $permissionIdentifier],
                        'type' => 'admin',
                    ]
                );
                $userRole->permissions()->syncWithoutDetaching([$permission->id]);
            }
        }

        return $user;
    }

    protected function createUser(): \App\Models\User
    {
        $userRole = \App\Models\Role::where('identifier', 'user')->first();
        $user = \App\Models\User::factory()->create();

        if ($userRole) {
            $user->roles()->attach($userRole->id);
        }

        return $user;
    }
}
