<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Usuario extends Authenticatable implements FilamentUser, HasName
{
    public const ROL_ADMINISTRADOR = 'Administrador';

    public const ROL_GUIA = 'Guía';

    protected $table = 'usuario';

    protected $primaryKey = 'id_usuario';

    public $timestamps = false;

    // La tabla no tiene remember_token: el inicio de sesión no ofrece «Recordarme».
    protected $rememberTokenName = false;

    protected $fillable = [
        'correo',
        'password',
        'rol',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function guia(): HasOne
    {
        return $this->hasOne(Guia::class, 'id_usuario', 'id_usuario');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return in_array($this->rol, [self::ROL_ADMINISTRADOR, self::ROL_GUIA], true);
    }

    // Filament muestra este nombre en el menú de la cuenta; la tabla no tiene una columna de nombre.
    public function getFilamentName(): string
    {
        return $this->correo;
    }
}
