<?php

namespace Database\Seeders;

use App\Models\Guia;
use App\Models\Usuario;
use Illuminate\Database\Seeder;

class GuiaSeeder extends Seeder
{
    // Cada cuenta con rol Guía de UsuarioSeeder necesita su fila en guia.
    public function run(): void
    {
        $nombrePorCorreo = [
            'guia@caminodelinca.test' => ['nombre' => 'Rosa', 'apellido' => 'Quispe'],
            'luko@caminodelinca.test' => ['nombre' => 'Andrés', 'apellido' => 'Mamani'],
            'ojosverdes@caminodelinca.test' => ['nombre' => 'Julia', 'apellido' => 'Condori'],
        ];

        foreach ($nombrePorCorreo as $correo => $nombreYApellido) {
            $usuario = Usuario::where('correo', $correo)->firstOrFail();

            Guia::firstOrCreate(['id_usuario' => $usuario->id_usuario], $nombreYApellido);
        }
    }
}
