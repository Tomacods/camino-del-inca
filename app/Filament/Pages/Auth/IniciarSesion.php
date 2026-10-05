<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

// La página de Filament pide «email» y ofrece «Recordarme»; la tabla usuario tiene «correo» y no tiene remember_token.
class IniciarSesion extends Login
{
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
