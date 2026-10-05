<?php

namespace Database\Seeders;

use App\Models\Usuario;
use Illuminate\Database\Seeder;

class UsuarioSeeder extends Seeder
{
    // Cuentas de prueba para entrar al panel. Las contraseñas se guardan cifradas por el cast «hashed» del modelo.
    public function run(): void
    {
        Usuario::create([
            'correo' => 'admin@caminodelinca.test',
            'password' => 'admin1234',
            'rol' => Usuario::ROL_ADMINISTRADOR,
        ]);

        Usuario::create([
            'correo' => 'guia@caminodelinca.test',
            'password' => 'guia1234',
            'rol' => Usuario::ROL_GUIA,
        ]);
        
        Usuario::create([
            'correo' => 'luko@caminodelinca.test',
            'password' => 'luko1234',
            'rol' => Usuario::ROL_GUIA,
        ]);

        Usuario::create([
            'correo' => 'ojosverdes@caminodelinca.test',
            'password' => 'ojosverdes1234',
            'rol' => Usuario::ROL_GUIA,
        ]);
    }
}
