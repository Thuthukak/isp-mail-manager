<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use App\Filament\Auth\CustomLogin;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('/admin')
            ->login(CustomLogin::class)
            ->brandName('ISP Mail Management')
            ->brandLogo(asset('images/logo.png'))
            ->favicon(asset('images/favicon.ico'))
            ->colors([
                'primary' => Color::Blue,
                'danger' => Color::Red,
                'gray' => Color::Gray,
                'info' => Color::Blue,
                'success' => Color::Emerald,
                'warning' => Color::Orange,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
                \App\Filament\Widgets\MailSystemStatsWidget::class,
                // \App\Filament\Widgets\SyncStatusWidget::class,
                // \App\Filament\Widgets\MailboxSizeWidget::class,
                // \App\Filament\Widgets\RecentActivitiesWidget::class,
                
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->navigationGroups([
                NavigationGroup::make('Mail Management')
                    ->label('Mail Management')
                    ->icon('heroicon-o-envelope')
                    ->collapsed(false),
                NavigationGroup::make('System')
                    ->label('System')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->collapsed(true),
                NavigationGroup::make('Reports')
                    ->label('Reports & Analytics')
                    ->icon('heroicon-o-chart-bar')
                    ->collapsed(true),
                NavigationGroup::make('Administration')
                    ->label('Administration')
                    ->icon('heroicon-o-users')
                    ->collapsed(true),
            ])
            ->navigationItems([
                NavigationItem::make('System Health')
                    ->url('/admin/system-health')
                    ->icon('heroicon-o-heart')
                    ->group('System')
                    ->sort(1),
                NavigationItem::make('Queue Monitor')
                    ->url('/admin/horizon')
                    ->icon('heroicon-o-queue-list')
                    ->group('System')
                    ->sort(2)
                    ->openUrlInNewTab(),
            ])
            
            ->maxContentWidth('full')
            ->sidebarCollapsibleOnDesktop()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s');
            // ->viteTheme('resources/css/filament/admin/theme.css');
    }
}