<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Page as PaginaDeRecurso;
use Filament\Schemas\Schema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

// La página de Filament pide «email» y ofrece «Recordarme»; la tabla usuario tiene «correo» y no tiene remember_token.
class IniciarSesion extends Login
{
    // CU-24: al entrar, Filament lleva a la página que se quiso abrir antes de iniciar sesión. Si esa página es de otro
    // rol (por ejemplo, Reservas para el guía), se descarta y se entra al inicio del panel, como en un acceso normal.
    public function authenticate(): ?LoginResponse
    {
        $respuesta = parent::authenticate();

        if ($respuesta !== null && ! self::puedeAbrir(session('url.intended'))) {
            session()->forget('url.intended');
        }

        return $respuesta;
    }

    // Una página de un recurso se puede abrir si el rol ve ese recurso. Las demás páginas no dependen del rol.
    private static function puedeAbrir(?string $direccion): bool
    {
        if ($direccion === null) {
            return true;
        }

        try {
            $pagina = Route::getRoutes()->match(Request::create($direccion))->getControllerClass();
        } catch (Throwable) {
            return false;
        }

        if ($pagina !== null && is_subclass_of($pagina, PaginaDeRecurso::class)) {
            return $pagina::getResource()::canViewAny();
        }

        return true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('correo')
                    ->label(__('filament-panels::auth/pages/login.form.email.label'))
                    ->email()
                    ->required()
                    ->autocomplete()
                    ->autofocus(),
                $this->getPasswordFormComponent(),
            ]);
    }

    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [
            'correo' => $data['correo'],
            'password' => $data['password'],
        ];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.correo' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }
}
